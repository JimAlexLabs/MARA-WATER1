# MARA-WATER Management System - Frontend

## 🚀 Overview

This is the React TypeScript frontend for the MARA-WATER Management System. It provides a modern, responsive web interface for managing all aspects of water company operations.

## 🏗️ Architecture

- **Framework**: React 18 with TypeScript
- **Styling**: Tailwind CSS
- **Routing**: React Router DOM
- **State Management**: React Context API
- **HTTP Client**: Axios
- **Icons**: Lucide React
- **Notifications**: React Hot Toast
- **Charts**: Recharts (for future analytics)

## 📋 Prerequisites

- Node.js 16+
- npm or yarn
- Backend API running (Laravel)

## 🛠️ Installation

### 1. Install Dependencies

```bash
cd frontend/mara-water-web
npm install
```

### 2. Environment Configuration

Create a `.env` file in the root directory:

```env
REACT_APP_API_URL=http://localhost:8000/api/v1
REACT_APP_NAME=MARA-WATER Management System
```

### 3. Start Development Server

```bash
npm start
```

The application will be available at: `http://localhost:3000`

## 🔐 Authentication

The frontend uses token-based authentication with the Laravel Sanctum backend.

### Login Flow

1. User enters credentials on login page
2. Frontend sends credentials to `/api/v1/auth/login`
3. Backend returns JWT token
4. Token is stored in localStorage
5. Token is included in all subsequent API requests

### Protected Routes

- All routes except `/login` require authentication
- Unauthenticated users are redirected to login
- Expired tokens trigger automatic logout

## 📱 Features

### ✅ Implemented

- **Authentication System**

  - Login/logout functionality
  - Token management
  - Protected routes
  - User profile management

- **Responsive Design**

  - Mobile-first approach
  - Responsive sidebar navigation
  - Touch-friendly interface

- **Modern UI/UX**

  - Clean, professional design
  - Consistent color scheme
  - Smooth animations
  - Toast notifications

- **Dashboard**
  - KPI overview cards
  - Recent alerts
  - Activity feed
  - Quick action buttons

### 🚧 Coming Soon

- **QA Module**

  - Water test recording
  - Batch management
  - Quality thresholds
  - Non-conformance tracking

- **Production Module**

  - Production planning
  - Packaging runs
  - Cleaning schedules
  - Yield tracking

- **Inventory Module**

  - Stock management
  - Purchase orders
  - Goods receipts
  - Stock counts

- **Sales Module**

  - Customer management
  - Order processing
  - Delivery tracking
  - Returns management

- **Finance Module**

  - Invoice generation
  - Payment processing
  - Bank reconciliation
  - Debt management

- **Fleet Module**

  - Vehicle management
  - Maintenance schedules
  - Fuel tracking
  - Driver assignments

- **HR Module**

  - Attendance tracking
  - Uniform compliance
  - Safety checks
  - Leave management

- **Reports Module**
  - Production reports
  - Sales analytics
  - Financial reports
  - Performance dashboards

## 🎨 Design System

### Colors

- **Primary**: Blue (#3B82F6)
- **Secondary**: Green (#22C55E)
- **Accent**: Orange (#F2751A)
- **Success**: Green (#16A34A)
- **Warning**: Orange (#F59E0B)
- **Error**: Red (#EF4444)

### Components

- **Buttons**: Primary, secondary, success, danger, warning variants
- **Cards**: Header, body, footer sections
- **Forms**: Input fields, labels, validation
- **Tables**: Sortable, paginated data tables
- **Badges**: Status indicators
- **Modals**: Overlay dialogs
- **Sidebar**: Collapsible navigation

## 📁 Project Structure

```
src/
├── components/          # Reusable UI components
│   ├── Layout.tsx      # Main layout with sidebar
│   └── ...
├── contexts/           # React contexts
│   └── AuthContext.tsx # Authentication state
├── pages/              # Page components
│   ├── LoginPage.tsx   # Login page
│   ├── DashboardPage.tsx # Dashboard
│   └── ...
├── hooks/              # Custom React hooks
├── services/           # API service functions
├── types/              # TypeScript type definitions
├── utils/              # Utility functions
└── styles/             # CSS and styling
```

## 🔧 Development

### Available Scripts

```bash
# Start development server
npm start

# Build for production
npm run build

# Run tests
npm test

# Eject from Create React App
npm run eject
```

### Code Style

- **TypeScript**: Strict mode enabled
- **ESLint**: Code linting
- **Prettier**: Code formatting
- **Tailwind**: Utility-first CSS

### State Management

- **Context API**: For global state (auth, user)
- **Local State**: For component-specific state
- **Props**: For parent-child communication

## 🔒 Security

- **HTTPS**: Required in production
- **Token Storage**: Secure localStorage usage
- **Input Validation**: Client-side validation
- **XSS Protection**: React's built-in protection
- **CSRF Protection**: Token-based protection

## 📊 Performance

- **Code Splitting**: Route-based splitting
- **Lazy Loading**: Component lazy loading
- **Image Optimization**: WebP format support
- **Caching**: Browser caching strategies
- **Bundle Analysis**: Webpack bundle analyzer

## 🧪 Testing

```bash
# Run unit tests
npm test

# Run tests with coverage
npm test -- --coverage

# Run tests in watch mode
npm test -- --watch
```

## 🚀 Deployment

### Production Build

```bash
npm run build
```

### Environment Variables

```env
REACT_APP_API_URL=https://api.marawater.com/api/v1
REACT_APP_NAME=MARA-WATER Management System
NODE_ENV=production
```

### Deployment Options

- **Netlify**: Static site hosting
- **Vercel**: React-optimized hosting
- **AWS S3**: Static website hosting
- **Nginx**: Traditional web server

## 📱 Mobile Support

- **Responsive Design**: Mobile-first approach
- **Touch Gestures**: Swipe navigation
- **Offline Support**: Service worker (planned)
- **PWA**: Progressive Web App features

## 🔄 API Integration

### Base Configuration

```typescript
// API base URL
const API_BASE_URL =
  process.env.REACT_APP_API_URL || "http://localhost:8000/api/v1";

// Axios interceptors for auth
axios.interceptors.request.use((config) => {
  const token = localStorage.getItem("auth_token");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
```

### Error Handling

- **Network Errors**: Automatic retry
- **Auth Errors**: Redirect to login
- **Validation Errors**: Display field errors
- **Server Errors**: User-friendly messages

## 📈 Analytics

- **User Tracking**: Page views, actions
- **Performance Monitoring**: Load times, errors
- **Business Metrics**: Usage patterns
- **A/B Testing**: Feature testing (planned)

## 🔧 Troubleshooting

### Common Issues

1. **API Connection Failed**

   - Check backend server is running
   - Verify API URL in .env
   - Check CORS configuration

2. **Authentication Issues**

   - Clear localStorage
   - Check token expiration
   - Verify backend auth endpoints

3. **Build Errors**
   - Clear node_modules and reinstall
   - Check TypeScript errors
   - Verify all dependencies

## 📞 Support

For technical support or questions:

- Email: tech@marawater.com
- Documentation: `/docs/frontend`
- Issues: GitHub repository

## 📄 License

This project is proprietary software for MARA-WATER Ltd.

---

**MARA-WATER Management System** - Built with React 18 & TypeScript
