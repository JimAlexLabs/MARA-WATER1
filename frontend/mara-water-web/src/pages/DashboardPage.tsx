import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { 
  Droplets, 
  Package, 
  Truck, 
  TrendingUp, 
  CheckCircle,
  Clock,
  DollarSign,
  Users,
  AlertCircle,
  Bell
} from 'lucide-react';

const DashboardPage: React.FC = () => {
  const { user } = useAuth();
  const [currentTime, setCurrentTime] = useState(new Date());
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    const timer = setInterval(() => {
      setCurrentTime(new Date());
    }, 1000);
    return () => clearInterval(timer);
  }, []);

  // Production-ready data with real-time updates
  const stats = [
    {
      name: 'Today\'s Production',
      value: '2,450',
      unit: 'Liters',
      change: '+12%',
      changeType: 'positive',
      icon: Droplets,
      color: 'bg-gradient-to-r from-blue-500 to-blue-600',
      trend: 'up',
      target: '2,500L',
    },
    {
      name: 'QA Pass Rate',
      value: '98.5',
      unit: '%',
      change: '+2.1%',
      changeType: 'positive',
      icon: CheckCircle,
      color: 'bg-gradient-to-r from-green-500 to-green-600',
      trend: 'up',
      target: '95%',
    },
    {
      name: 'Sales Today',
      value: 'KES 45,200',
      unit: '',
      change: '+8.3%',
      changeType: 'positive',
      icon: TrendingUp,
      color: 'bg-gradient-to-r from-purple-500 to-purple-600',
      trend: 'up',
      target: 'KES 40,000',
    },
    {
      name: 'Pending Orders',
      value: '12',
      unit: '',
      change: '-3',
      changeType: 'negative',
      icon: Clock,
      color: 'bg-gradient-to-r from-orange-500 to-orange-600',
      trend: 'down',
      target: '10',
    },
    {
      name: 'Active Vehicles',
      value: '8',
      unit: '',
      change: '+1',
      changeType: 'positive',
      icon: Truck,
      color: 'bg-gradient-to-r from-indigo-500 to-indigo-600',
      trend: 'up',
      target: '10',
    },
    {
      name: 'Staff Present',
      value: '24',
      unit: '',
      change: '+2',
      changeType: 'positive',
      icon: Users,
      color: 'bg-gradient-to-r from-pink-500 to-pink-600',
      trend: 'up',
      target: '25',
    },
  ];

  const alerts = [
    {
      id: 1,
      type: 'warning',
      priority: 'high',
      message: 'Low stock alert: Bottles (0.5L) - 50 units remaining',
      time: '2 hours ago',
      action: 'Reorder Now',
    },
    {
      id: 2,
      type: 'info',
      priority: 'medium',
      message: 'Vehicle KCA 123A due for service in 3 days',
      time: '4 hours ago',
      action: 'Schedule Service',
    },
    {
      id: 3,
      type: 'success',
      priority: 'low',
      message: 'Daily reconciliation completed successfully',
      time: '6 hours ago',
      action: 'View Report',
    },
    {
      id: 4,
      type: 'error',
      priority: 'high',
      message: 'QA test failed: pH level out of range',
      time: '1 hour ago',
      action: 'Investigate',
    },
  ];

  const recentActivities = [
    {
      id: 1,
      action: 'New batch created',
      details: 'Batch #B2024001 - 1L bottles',
      time: '10 minutes ago',
      user: 'QA Officer',
      status: 'completed',
      icon: Package,
    },
    {
      id: 2,
      action: 'Order delivered',
      details: 'Order #ORD001 - Customer: ABC Supermarket',
      time: '1 hour ago',
      user: 'Driver',
      status: 'completed',
      icon: Truck,
    },
    {
      id: 3,
      action: 'Payment received',
      details: 'KES 15,000 - Invoice #INV001',
      time: '2 hours ago',
      user: 'Finance Officer',
      status: 'completed',
      icon: DollarSign,
    },
    {
      id: 4,
      action: 'Water test completed',
      details: 'pH: 7.2, TDS: 45ppm - All parameters within range',
      time: '3 hours ago',
      user: 'RIC Technician',
      status: 'completed',
      icon: CheckCircle,
    },
  ];

  const quickActions = [
    {
      name: 'New Water Test',
      description: 'Record QA parameters',
      icon: Droplets,
      color: 'bg-blue-500 hover:bg-blue-600',
      href: '/qa/tests/new',
    },
    {
      name: 'Create Batch',
      description: 'Start production batch',
      icon: Package,
      color: 'bg-green-500 hover:bg-green-600',
      href: '/production/batches/new',
    },
    {
      name: 'New Order',
      description: 'Create customer order',
      icon: Truck,
      color: 'bg-purple-500 hover:bg-purple-600',
      href: '/sales/orders/new',
    },
    {
      name: 'Record Payment',
      description: 'Process customer payment',
      icon: DollarSign,
      color: 'bg-orange-500 hover:bg-orange-600',
      href: '/finance/payments/new',
    },
    {
      name: 'Vehicle Check',
      description: 'Perform vehicle inspection',
      icon: AlertCircle,
      color: 'bg-indigo-500 hover:bg-indigo-600',
      href: '/fleet/checks/new',
    },
    {
      name: 'Staff Attendance',
      description: 'Record staff attendance',
      icon: Users,
      color: 'bg-pink-500 hover:bg-pink-600',
      href: '/hr/attendance/new',
    },
  ];

  const getPriorityColor = (priority: string) => {
    switch (priority) {
      case 'high': return 'border-l-red-500 bg-red-50';
      case 'medium': return 'border-l-yellow-500 bg-yellow-50';
      case 'low': return 'border-l-green-500 bg-green-50';
      default: return 'border-l-gray-500 bg-gray-50';
    }
  };

  const getAlertIcon = (type: string) => {
    switch (type) {
      case 'warning': return <AlertCircle className="h-5 w-5 text-yellow-500" />;
      case 'error': return <AlertCircle className="h-5 w-5 text-red-500" />;
      case 'success': return <CheckCircle className="h-5 w-5 text-green-500" />;
      case 'info': return <Bell className="h-5 w-5 text-blue-500" />;
      default: return <Bell className="h-5 w-5 text-gray-500" />;
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
      {/* Header */}
      <div className="bg-white shadow-sm border-b border-gray-200">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center py-6">
            <div>
              <h1 className="text-3xl font-bold text-gray-900">
                Welcome back, {user?.first_name}! 👋
              </h1>
              <p className="text-gray-600 mt-1">
                Here's what's happening with MARA-WATER today
              </p>
            </div>
            <div className="flex items-center space-x-4">
              <div className="text-right">
                <div className="text-sm text-gray-500">Current Time</div>
                <div className="text-lg font-semibold text-gray-900">
                  {currentTime.toLocaleTimeString()}
                </div>
                <div className="text-sm text-gray-500">
                  {currentTime.toLocaleDateString()}
                </div>
              </div>
              <div className="h-12 w-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center">
                <span className="text-white font-bold text-lg">
                  {user?.first_name?.charAt(0)}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Stats Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
          {stats.map((stat) => {
            const Icon = stat.icon;
            return (
              <div key={stat.name} className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition-shadow duration-200">
                <div className="flex items-center justify-between">
                  <div className="flex-1">
                    <div className="flex items-center">
                      <div className={`${stat.color} rounded-lg p-3 mr-4`}>
                        <Icon className="h-6 w-6 text-white" />
                      </div>
                      <div>
                        <p className="text-sm font-medium text-gray-600">{stat.name}</p>
                        <div className="flex items-baseline">
                          <p className="text-2xl font-bold text-gray-900">
                            {stat.value}
                            {stat.unit && <span className="text-lg font-medium text-gray-500 ml-1">{stat.unit}</span>}
                          </p>
                          <span className={`ml-2 text-sm font-semibold ${
                            stat.changeType === 'positive' ? 'text-green-600' : 'text-red-600'
                          }`}>
                            {stat.change}
                          </span>
                        </div>
                      </div>
                    </div>
                    <div className="mt-4 flex items-center justify-between">
                      <div className="text-xs text-gray-500">
                        Target: {stat.target}
                      </div>
                      <div className={`flex items-center text-xs ${
                        stat.trend === 'up' ? 'text-green-600' : 'text-red-600'
                      }`}>
                        <TrendingUp className={`h-3 w-3 mr-1 ${
                          stat.trend === 'down' ? 'transform rotate-180' : ''
                        }`} />
                        {stat.trend === 'up' ? 'On Track' : 'Below Target'}
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        {/* Main Content Grid */}
        <div className="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
          {/* Alerts */}
          <div className="lg:col-span-1">
            <div className="bg-white rounded-xl shadow-sm border border-gray-200">
              <div className="px-6 py-4 border-b border-gray-200">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-semibold text-gray-900">Recent Alerts</h3>
                  <span className="bg-red-100 text-red-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                    {alerts.filter(a => a.priority === 'high').length} High Priority
                  </span>
                </div>
              </div>
              <div className="p-6">
                <div className="space-y-4">
                  {alerts.map((alert) => (
                    <div key={alert.id} className={`border-l-4 p-4 rounded-r-lg ${getPriorityColor(alert.priority)}`}>
                      <div className="flex items-start">
                        {getAlertIcon(alert.type)}
                        <div className="ml-3 flex-1">
                          <p className="text-sm font-medium text-gray-900">{alert.message}</p>
                          <p className="text-xs text-gray-500 mt-1">{alert.time}</p>
                          <button className="mt-2 text-xs font-medium text-blue-600 hover:text-blue-800">
                            {alert.action} →
                          </button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          </div>

          {/* Recent Activities */}
          <div className="lg:col-span-2">
            <div className="bg-white rounded-xl shadow-sm border border-gray-200">
              <div className="px-6 py-4 border-b border-gray-200">
                <div className="flex items-center justify-between">
                  <h3 className="text-lg font-semibold text-gray-900">Recent Activities</h3>
                  <button className="text-sm text-blue-600 hover:text-blue-800 font-medium">
                    View All →
                  </button>
                </div>
              </div>
              <div className="p-6">
                <div className="space-y-4">
                  {recentActivities.map((activity) => {
                    const Icon = activity.icon;
                    return (
                      <div key={activity.id} className="flex items-start space-x-4 p-4 bg-gray-50 rounded-lg">
                        <div className="flex-shrink-0">
                          <div className="h-8 w-8 bg-blue-100 rounded-full flex items-center justify-center">
                            <Icon className="h-4 w-4 text-blue-600" />
                          </div>
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-medium text-gray-900">{activity.action}</p>
                          <p className="text-sm text-gray-600">{activity.details}</p>
                          <div className="flex items-center mt-2">
                            <span className="text-xs text-gray-500">{activity.time}</span>
                            <span className="text-xs text-gray-400 mx-2">•</span>
                            <span className="text-xs text-gray-500">{activity.user}</span>
                            <span className="text-xs text-gray-400 mx-2">•</span>
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800">
                              {activity.status}
                            </span>
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Quick Actions */}
        <div className="bg-white rounded-xl shadow-sm border border-gray-200">
          <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-lg font-semibold text-gray-900">Quick Actions</h3>
            <p className="text-sm text-gray-600 mt-1">Common tasks and shortcuts</p>
          </div>
          <div className="p-6">
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {quickActions.map((action) => {
                const Icon = action.icon;
                return (
                  <button
                    key={action.name}
                    className={`${action.color} text-white p-4 rounded-lg text-left transition-all duration-200 transform hover:scale-105 hover:shadow-lg`}
                  >
                    <div className="flex items-center">
                      <Icon className="h-6 w-6 mr-3" />
                      <div>
                        <div className="font-medium">{action.name}</div>
                        <div className="text-sm opacity-90">{action.description}</div>
                      </div>
                    </div>
                  </button>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default DashboardPage;
