# MARA-WATER Management System

## 🚀 Enterprise-Grade Water Company Management Platform

A comprehensive, modern web and mobile system designed for water company operations management. Built with Laravel 10, React 18, and MySQL, featuring real-time dashboards, role-based access control, and end-to-end operational workflows.

## 📋 Table of Contents

- [Project Overview](#project-overview)
- [System Architecture](#system-architecture)
- [Technology Stack](#technology-stack)
- [Database Schema](#database-schema)
- [Installation & Setup](#installation--setup)
- [API Documentation](#api-documentation)
- [Frontend Components](#frontend-components)
- [Deployment Strategy](#deployment-strategy)
- [Development Workflow](#development-workflow)
- [Troubleshooting](#troubleshooting)

## 🎯 Project Overview

### Purpose
MARA-WATER is designed to handle complete end-to-end daily operations for water companies including:
- Quality Assurance (QA) testing and monitoring
- Production and batching management
- Packaging and inventory control
- Sales and distribution tracking
- Finance and reconciliation
- Fleet and vehicle management
- Staff attendance and HR
- Media management (photos, voice notes)
- Real-time analytics and reporting

### Key Features
- **Single Director Login**: One admin account manages all operations
- **Role-Based Access Control**: Granular permissions per role
- **Real-Time Dashboards**: Live data visualization
- **Mobile-Ready**: Responsive design for field operations
- **Audit Trail**: Complete action logging
- **API-First**: RESTful API for mobile apps
- **Production Ready**: Enterprise-grade security and performance

## 🏗️ System Architecture

### Monorepo Structure
```
WATER LTD/
├── backend/
│   └── mara-water-api/          # Laravel 10 Backend
│       ├── app/
│       │   ├── Http/Controllers/Api/
│       │   ├── Models/
│       │   └── Providers/
│       ├── database/
│       ├── routes/
│       ├── config/
│       └── scripts/             # Server management scripts
├── frontend/
│   └── mara-water-web/          # React 18 Frontend
│       ├── src/
│       │   ├── components/
│       │   ├── pages/
│       │   ├── contexts/
│       │   └── utils/
│       └── public/
├── mobile/                      # React Native (Future)
├── shared/                      # Shared utilities
└── docs/                        # Documentation
```

### Database Architecture
- **MySQL 8.0+** with XAMPP
- **UUID Primary Keys** for all tables
- **Soft Deletes** for data retention
- **Audit Fields** (created_by, updated_by, timestamps)
- **Foreign Key Constraints** for data integrity
- **Indexes** on frequently queried fields

## 🛠️ Technology Stack

### Backend
- **Framework**: Laravel 10.0
- **PHP**: 8.2+
- **Database**: MySQL 8.0+
- **Authentication**: Laravel Sanctum
- **API**: RESTful with JSON responses
- **Validation**: Laravel Form Requests
- **Caching**: Redis (production)

### Frontend
- **Framework**: React 18
- **Language**: TypeScript
- **Styling**: Tailwind CSS
- **State Management**: React Context API
- **HTTP Client**: Axios
- **Routing**: React Router DOM
- **Icons**: Lucide React
- **Notifications**: React Hot Toast

### Development Tools
- **Package Manager**: npm
- **Build Tool**: Vite (React)
- **Code Quality**: ESLint, Prettier
- **Version Control**: Git
- **Server Management**: Custom Bash scripts

## 🗄️ Database Schema

### Core Tables

#### Authentication & Users
```sql
-- Users table with UUID primary keys
users (
    id CHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE,
    password_hash VARCHAR(255),
    first_name VARCHAR(255),
    last_name VARCHAR(255),
    phone VARCHAR(20),
    avatar_url VARCHAR(500),
    status ENUM('active', 'inactive'),
    role_id CHAR(36),
    department_id CHAR(36),
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP NULL
)

-- Roles for RBAC
roles (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) UNIQUE,
    name VARCHAR(255),
    description TEXT,
    is_system BOOLEAN DEFAULT FALSE
)

-- Permissions system
permissions (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(100) UNIQUE,
    name VARCHAR(255),
    module VARCHAR(100),
    description TEXT
)

-- Role-Permission mapping
role_permissions (
    role_id CHAR(36),
    permission_id CHAR(36),
    PRIMARY KEY (role_id, permission_id)
)
```

#### Business Logic Tables
```sql
-- Water quality tests
water_tests (
    id CHAR(36) PRIMARY KEY,
    test_type ENUM('baseline', 'random', 'retest'),
    recorded_at TIMESTAMP,
    ph DECIMAL(5,2),
    tds DECIMAL(10,2),
    chlorine DECIMAL(10,3),
    recorded_by CHAR(36),
    ric_verified_by CHAR(36),
    status ENUM('pending', 'pass', 'fail')
)

-- Production batches
batches (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) UNIQUE,
    sku_id CHAR(36),
    manufacture_date DATE,
    expiry_date DATE,
    planned_qty INT,
    status ENUM('open', 'in_progress', 'closed')
)

-- Customer orders
orders (
    id CHAR(36) PRIMARY KEY,
    order_no VARCHAR(50) UNIQUE,
    customer_id CHAR(36),
    sales_officer_id CHAR(36),
    status ENUM('draft', 'confirmed', 'dispatched', 'delivered'),
    order_date DATE
)
```

### Complete Schema
The full database schema includes 25+ tables covering all business operations. See `database/mara_water_complete_all_modules.sql` for complete schema.

## 🚀 Installation & Setup

### Prerequisites
- **XAMPP** (MySQL + Apache)
- **PHP 8.2+**
- **Node.js 18+**
- **Git**

### Quick Start
```bash
# 1. Clone repository
git clone https://github.com/JimAlexLabs/MARA-WATER1.git
cd MARA-WATER1

# 2. Start XAMPP MySQL
# Open XAMPP Control Panel and start MySQL

# 3. Import database
mysql -u root -p < backend/mara-water-api/mara_water_complete_all_modules.sql

# 4. Setup backend
cd backend/mara-water-api
composer install
cp .env.example .env
php artisan key:generate
php artisan config:clear

# 5. Setup frontend
cd ../../frontend/mara-water-web
npm install

# 6. Start servers
cd ../../backend/mara-water-api
./start-permanent.sh
```

### Server Management Scripts
```bash
# Start servers permanently
./start-permanent.sh

# Check server status
./status.sh

# Stop servers
./stop-permanent.sh

# Quick start with timeout
./quick-start.sh
```

### Environment Configuration
```env
# Backend (.env)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mara_water
DB_USERNAME=root
DB_PASSWORD=

# Frontend (.env)
REACT_APP_API_URL=http://localhost:8005/api/v1
```

## 📚 API Documentation

### Authentication Endpoints

#### Login
```http
POST /api/v1/auth/login
Content-Type: application/json

{
    "email": "director@marawater.com",
    "password": "password"
}
```

**Response:**
```json
{
    "success": true,
    "message": "Login successful",
    "data": {
        "user": {
            "id": "550e8400-e29b-41d4-a716-446655440201",
            "email": "director@marawater.com",
            "first_name": "Managing",
            "last_name": "Director",
            "role": {
                "code": "ADMIN",
                "name": "Director / Admin"
            }
        },
        "token": "2|QEXFV79RTqyra7TJ5mw6XWl5lX4IPSaiVz325mfI034d9d15",
        "token_type": "Bearer",
        "expires_in": 2592000
    }
}
```

#### Logout
```http
POST /api/v1/auth/logout
Authorization: Bearer {token}
```

### Dashboard Endpoints

#### Health Check
```http
GET /api/health
```

**Response:**
```json
{
    "status": "healthy",
    "timestamp": "2025-08-30T22:43:07.068648Z",
    "version": "1.0.0"
}
```

### Protected Routes
All business endpoints require authentication:
```http
Authorization: Bearer {token}
```

## 🎨 Frontend Components

### Core Components

#### Layout Structure
```typescript
// src/components/Layout.tsx
- Header with user info and notifications
- Sidebar with navigation menu
- Main content area
- Footer with system status
```

#### Authentication Context
```typescript
// src/contexts/AuthContext.tsx
- User state management
- Login/logout functions
- Token handling
- API configuration
```

#### Dashboard Components
```typescript
// src/pages/DashboardPage.tsx
- Real-time metrics cards
- Charts and graphs
- Recent activity feed
- Quick action buttons
```

### Styling System
- **Tailwind CSS** for utility-first styling
- **Custom components** for consistent UI
- **Responsive design** for mobile compatibility
- **Dark mode support** (planned)

## 🚀 Deployment Strategy

### Development Environment
- **Local Development**: XAMPP + Node.js
- **Version Control**: Git with feature branches
- **Testing**: Manual testing + API testing
- **Database**: Local MySQL instance

### Staging Environment
```bash
# Recommended staging setup
- Ubuntu 20.04+ server
- Nginx web server
- MySQL 8.0
- Redis for caching
- SSL certificates
- Automated backups
```

### Production Environment
```bash
# Production deployment
1. Server: Ubuntu 22.04 LTS
2. Web Server: Nginx with SSL
3. Database: MySQL 8.0 with replication
4. Caching: Redis cluster
5. Monitoring: New Relic / DataDog
6. Backup: Automated daily backups
7. CI/CD: GitHub Actions
```

### Docker Deployment (Recommended)
```dockerfile
# Backend Dockerfile
FROM php:8.2-fpm
RUN apt-get update && apt-get install -y \
    mysql-client \
    && docker-php-ext-install pdo pdo_mysql
COPY . /var/www/html
RUN composer install --no-dev --optimize-autoloader
```

## 🔄 Development Workflow

### Git Workflow
```bash
# Feature development
git checkout -b feature/new-feature
# Make changes
git add .
git commit -m "feat: add new feature"
git push origin feature/new-feature
# Create pull request
```

### Code Standards
- **PHP**: PSR-12 coding standards
- **JavaScript**: ESLint + Prettier
- **TypeScript**: Strict mode enabled
- **Database**: Consistent naming conventions

### Testing Strategy
```bash
# Backend testing
php artisan test

# Frontend testing
npm test

# API testing
curl -X POST http://localhost:8005/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}'
```

## 🛠️ Troubleshooting

### Common Issues

#### Server Connection Refused
```bash
# Check if servers are running
./status.sh

# Restart servers
./stop-permanent.sh
./start-permanent.sh
```

#### Database Connection Issues
```bash
# Check MySQL status
sudo /Applications/XAMPP/xamppfiles/bin/mysql.server status

# Test database connection
mysql -u root -e "USE mara_water; SELECT COUNT(*) FROM users;"
```

#### Frontend Build Issues
```bash
# Clear node modules and reinstall
rm -rf node_modules package-lock.json
npm install

# Clear cache
npm cache clean --force
```

### Log Files
- **Laravel logs**: `backend/mara-water-api/storage/logs/laravel.log`
- **React logs**: `frontend/mara-water-web/react.log`
- **Server logs**: `backend/mara-water-api/laravel.log`

### Performance Optimization
```bash
# Backend optimization
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Frontend optimization
npm run build
```

## 📊 System Requirements

### Minimum Requirements
- **CPU**: 2 cores
- **RAM**: 4GB
- **Storage**: 20GB
- **Network**: 10Mbps

### Recommended Requirements
- **CPU**: 4+ cores
- **RAM**: 8GB+
- **Storage**: 50GB+ SSD
- **Network**: 100Mbps+

## 🔐 Security Considerations

### Authentication
- **JWT tokens** with expiration
- **Password hashing** with bcrypt
- **Session management** with Sanctum
- **CSRF protection** enabled

### Data Protection
- **Input validation** on all endpoints
- **SQL injection** prevention
- **XSS protection** headers
- **HTTPS enforcement** in production

### Access Control
- **Role-based permissions**
- **API rate limiting**
- **Audit logging** for all actions
- **Data encryption** at rest

## 📈 Future Enhancements

### Planned Features
- [ ] Mobile app (React Native)
- [ ] Real-time notifications (WebSockets)
- [ ] Advanced reporting (PDF exports)
- [ ] Multi-language support
- [ ] Offline capability
- [ ] Advanced analytics dashboard

### Technical Improvements
- [ ] Microservices architecture
- [ ] GraphQL API
- [ ] Event sourcing
- [ ] CQRS pattern
- [ ] Automated testing suite
- [ ] Performance monitoring

## 🤝 Contributing

### Development Setup
1. Fork the repository
2. Create feature branch
3. Make changes with tests
4. Submit pull request
5. Code review process

### Code Review Checklist
- [ ] Code follows standards
- [ ] Tests pass
- [ ] Documentation updated
- [ ] No security vulnerabilities
- [ ] Performance impact assessed

## 📞 Support

### Contact Information
- **Developer**: Jim Alex Labs
- **Email**: support@marawater.com
- **Documentation**: [Project Wiki](https://github.com/JimAlexLabs/MARA-WATER1/wiki)

### Issue Reporting
Please report bugs and feature requests through GitHub Issues with:
- Clear description of the problem
- Steps to reproduce
- Expected vs actual behavior
- Environment details

---

## 📄 License

This project is proprietary software developed for MARA-WATER Ltd. All rights reserved.

**Version**: 1.0.0  
**Last Updated**: August 30, 2025  
**Status**: Production Ready ✅
