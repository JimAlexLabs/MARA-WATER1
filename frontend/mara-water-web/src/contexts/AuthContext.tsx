import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import axios from 'axios';
import toast from 'react-hot-toast';

// API Configuration
const API_BASE_URL = process.env.REACT_APP_API_URL || 'http://localhost:8005/api/v1';

// Types
interface User {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  full_name: string;
  phone: string;
  avatar_url?: string;
  theme?: 'light' | 'dark';
  status: string;
  role: {
    id: string;
    code: string;
    name: string;
  };
  department: {
    id: string;
    code: string;
    name: string;
  };
  permissions: Array<{
    code: string;
    name: string;
    module: string;
  }>;
}

interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  loading: boolean;
  login: (email: string, password: string) => Promise<boolean>;
  logout: () => void;
  updateProfile: (data: Partial<User>) => Promise<boolean>;
  theme: 'light' | 'dark';
  toggleTheme: () => void;
}

// Create Context
const AuthContext = createContext<AuthContextType | undefined>(undefined);

// Round 2 Phase 2 (dark mode): applies/removes the `dark` class Tailwind's
// class-strategy dark mode looks for, and caches the choice in
// localStorage purely so the very first paint (before the user object has
// loaded from /auth/me) doesn't flash the wrong theme. localStorage is
// never the source of truth here -- the user's `theme` column is -- it's
// just a same-browser guess for the instant before that response arrives.
const applyThemeToDom = (theme: 'light' | 'dark') => {
  document.documentElement.classList.toggle('dark', theme === 'dark');
  try {
    localStorage.setItem('theme_hint', theme);
  } catch {
    // Private browsing / storage blocked -- the toggle still works for
    // this page load, it just won't have a hint on the next one.
  }
};

const getThemeHint = (): 'light' | 'dark' => {
  try {
    return localStorage.getItem('theme_hint') === 'dark' ? 'dark' : 'light';
  } catch {
    return 'light';
  }
};

// Auth Provider Component
export const AuthProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);
  const [theme, setThemeState] = useState<'light' | 'dark'>(getThemeHint());

  // Apply the hinted theme immediately on first mount, before /auth/me has
  // had a chance to respond, so there's no flash of the wrong theme.
  useEffect(() => {
    applyThemeToDom(theme);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Once the real user loads (or changes), their saved theme is the
  // authority -- switch to it if it differs from the hint.
  useEffect(() => {
    if (user?.theme && user.theme !== theme) {
      setThemeState(user.theme);
      applyThemeToDom(user.theme);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user?.theme]);

  const toggleTheme = () => {
    const next: 'light' | 'dark' = theme === 'dark' ? 'light' : 'dark';
    setThemeState(next);
    applyThemeToDom(next);
    if (user) {
      setUser({ ...user, theme: next });
      // Silent, best-effort -- a failed save just means it reverts to the
      // old theme next login, not worth interrupting the toggle over.
      axios.patch('/auth/profile', { theme: next }).catch(() => {});
    }
  };

  // Initialize axios with base URL
  useEffect(() => {
    axios.defaults.baseURL = API_BASE_URL;
    
    // Add request interceptor to include auth token
    axios.interceptors.request.use(
      (config) => {
        const token = localStorage.getItem('auth_token');
        if (token) {
          config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
      },
      (error) => {
        return Promise.reject(error);
      }
    );

    // Add response interceptor to handle auth errors
    axios.interceptors.response.use(
      (response) => response,
      (error) => {
        if (error.response?.status === 401) {
          localStorage.removeItem('auth_token');
          setUser(null);
          window.location.href = '/login';
        }
        return Promise.reject(error);
      }
    );
  }, []);

  // Check if user is already authenticated on app load
  useEffect(() => {
    const checkAuth = async () => {
      const token = localStorage.getItem('auth_token');
      if (token) {
        try {
          const response = await axios.get('/auth/me');
          if (response.data.success) {
            setUser(response.data.data.user);
          } else {
            localStorage.removeItem('auth_token');
          }
        } catch (error) {
          localStorage.removeItem('auth_token');
        }
      }
      setLoading(false);
    };

    checkAuth();
  }, []);

  // Login function
  const login = async (email: string, password: string): Promise<boolean> => {
    try {
      setLoading(true);
      const response = await axios.post('/auth/login', {
        email,
        password,
        device_info: 'web'
      });

      if (response.data.success) {
        const { user, token } = response.data.data;
        localStorage.setItem('auth_token', token);
        setUser(user);
        toast.success('Login successful!');
        return true;
      } else {
        toast.error(response.data.message || 'Login failed');
        return false;
      }
    } catch (error: any) {
      const message = error.response?.data?.message || 'Login failed. Please try again.';
      toast.error(message);
      return false;
    } finally {
      setLoading(false);
    }
  };

  // Logout function
  const logout = async () => {
    try {
      await axios.post('/auth/logout');
    } catch (error) {
      console.error('Logout error:', error);
    } finally {
      localStorage.removeItem('auth_token');
      setUser(null);
      toast.success('Logged out successfully');
    }
  };

  // Update profile function
  const updateProfile = async (data: Partial<User>): Promise<boolean> => {
    try {
      const response = await axios.patch('/auth/profile', data);
      if (response.data.success) {
        setUser(response.data.data.user);
        toast.success('Profile updated successfully!');
        return true;
      } else {
        toast.error(response.data.message || 'Update failed');
        return false;
      }
    } catch (error: any) {
      const message = error.response?.data?.message || 'Update failed. Please try again.';
      toast.error(message);
      return false;
    }
  };

  const value: AuthContextType = {
    user,
    isAuthenticated: !!user,
    loading,
    login,
    logout,
    updateProfile,
    theme,
    toggleTheme,
  };

  return (
    <AuthContext.Provider value={value}>
      {children}
    </AuthContext.Provider>
  );
};

// Custom hook to use auth context
export const useAuth = (): AuthContextType => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

// Helper function to check permissions
export const usePermissions = () => {
  const { user } = useAuth();
  
  const hasPermission = (permissionCode: string): boolean => {
    if (!user) return false;
    return (user.permissions ?? []).some(p => p.code === permissionCode);
  };

  const hasModulePermission = (module: string): boolean => {
    if (!user) return false;
    return (user.permissions ?? []).some(p => p.module === module);
  };

  const isDirector = (): boolean => {
    return user?.role?.code === 'ADMIN';
  };

  return {
    hasPermission,
    hasModulePermission,
    isDirector,
    permissions: user?.permissions || [],
  };
};
