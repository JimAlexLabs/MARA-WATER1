# MARA-WATER Management System - Backend API

## 🚀 Overview

This is the Laravel 10 backend API for the MARA-WATER Management System. It provides a comprehensive REST API for managing all aspects of water company operations including QA, production, inventory, sales, finance, fleet, and HR.

## 🏗️ Architecture

- **Framework**: Laravel 10 (PHP 8.2+)
- **Database**: MySQL (MARA-WATER database)
- **Authentication**: Laravel Sanctum (API tokens)
- **API**: RESTful with JSON responses
- **Versioning**: API v1

## 📋 Prerequisites

- PHP 8.2 or higher
- Composer
- MySQL 8.0 or higher
- XAMPP (for local development)
- MARA-WATER database (imported from SQL files)

## 🛠️ Installation

### 1. Clone and Setup

```bash
cd backend/mara-water-api
composer install
cp .env.example .env
```

### 2. Environment Configuration

Update `.env` file with your database settings:

```env
APP_NAME="MARA-WATER Management System"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=MARA-WATER
DB_USERNAME=root
DB_PASSWORD=

APP_TIMEZONE=Africa/Nairobi
APP_LOCALE=en
```

### 3. Generate Application Key

```bash
php artisan key:generate
```

### 4. Database Setup

Ensure your MARA-WATER database is imported and running in XAMPP phpMyAdmin.

### 5. Run the Application

```bash
php artisan serve
```

The API will be available at: `http://localhost:8000`

## 🔐 Authentication

The API uses Laravel Sanctum for authentication with API tokens.

### Login

```http
POST /api/v1/auth/login
Content-Type: application/json

{
    "email": "director@marawater.com",
    "password": "Admin@2024",
    "device_info": "web"
}
```

### Response

```json
{
    "success": true,
    "message": "Login successful",
    "data": {
        "user": {
            "id": "550e8400-e29b-41d4-a716-446655440001",
            "email": "director@marawater.com",
            "first_name": "Managing",
            "last_name": "Director",
            "full_name": "Managing Director",
            "phone": "+254700000000",
            "status": "active",
            "role": {
                "id": "550e8400-e29b-41d4-a716-446655440101",
                "code": "ADMIN",
                "name": "Director"
            },
            "department": {
                "id": "550e8400-e29b-41d4-a716-446655440008",
                "code": "MGMT",
                "name": "Management"
            }
        },
        "token": "1|abc123...",
        "token_type": "Bearer",
        "expires_in": 2592000
    }
}
```

### Using the Token

Include the token in the Authorization header:

```http
Authorization: Bearer 1|abc123...
```

## 📚 API Endpoints

### Authentication

- `POST /api/v1/auth/login` - User login
- `POST /api/v1/auth/refresh` - Refresh token
- `GET /api/v1/auth/me` - Get user profile
- `PATCH /api/v1/auth/profile` - Update user profile
- `POST /api/v1/auth/logout` - User logout

### Dashboard

- `GET /api/v1/dashboard` - System dashboard

### User Management

- `GET /api/v1/users` - List users
- `GET /api/v1/users/{id}` - Get user details

### QA & Production

- `GET /api/v1/qa/tests` - List water tests
- `GET /api/v1/qa/batches` - List production batches
- `GET /api/v1/qa/thresholds` - Get QA thresholds
- `GET /api/v1/production/plans` - List production plans
- `GET /api/v1/production/runs` - List packaging runs

### Inventory

- `GET /api/v1/inventory/stock` - Get stock levels
- `GET /api/v1/inventory/moves` - List stock movements

### Sales & Distribution

- `GET /api/v1/sales/customers` - List customers
- `GET /api/v1/sales/orders` - List orders
- `GET /api/v1/sales/manifests` - List driver manifests

### Finance

- `GET /api/v1/finance/invoices` - List invoices
- `GET /api/v1/finance/reconciliation` - Get reconciliation data

### Fleet

- `GET /api/v1/fleet/vehicles` - List vehicles
- `GET /api/v1/fleet/checks` - List vehicle checks

### HR

- `GET /api/v1/hr/attendance` - Get attendance data
- `GET /api/v1/hr/uniform` - Get uniform compliance

### Reports

- `GET /api/v1/reports/production` - Production reports
- `GET /api/v1/reports/sales` - Sales reports
- `GET /api/v1/reports/finance` - Finance reports

### Health Check

- `GET /health` - API health status

## 🔧 Development

### Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── AuthController.php
│   └── Middleware/
├── Models/
│   ├── User.php
│   ├── Role.php
│   ├── Department.php
│   ├── Permission.php
│   ├── UserSession.php
│   └── Audit.php
├── Providers/
└── Services/
```

### Key Models

- **User**: Core user management with role-based access
- **Role**: System roles (ADMIN, QA, RIC, etc.)
- **Department**: Organizational departments
- **Permission**: Granular permissions system
- **UserSession**: Session tracking for security
- **Audit**: Complete audit trail

### Adding New Endpoints

1. Create controller in `app/Http/Controllers/Api/`
2. Add routes in `routes/api.php`
3. Implement model relationships
4. Add validation and error handling

## 🔒 Security Features

- **API Token Authentication**: Secure token-based auth
- **Role-Based Access Control**: Granular permissions
- **Audit Logging**: Complete action tracking
- **Session Management**: Device and IP tracking
- **Input Validation**: Comprehensive validation rules
- **SQL Injection Protection**: Eloquent ORM protection

## 📊 Database Integration

The API connects to the MARA-WATER MySQL database with:

- **80+ Tables**: Complete business operations
- **UUID Primary Keys**: Secure identification
- **Soft Deletes**: Data preservation
- **Audit Fields**: Created/updated tracking
- **Foreign Keys**: Referential integrity
- **Indexes**: Performance optimization

## 🚀 Deployment

### Production Setup

1. Set `APP_ENV=production`
2. Set `APP_DEBUG=false`
3. Configure production database
4. Set up SSL certificates
5. Configure web server (Apache/Nginx)

### Environment Variables

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.marawater.com

DB_HOST=production-db-host
DB_DATABASE=MARA-WATER
DB_USERNAME=production-user
DB_PASSWORD=secure-password

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

## 🧪 Testing

```bash
# Run tests
php artisan test

# Run specific test
php artisan test --filter AuthControllerTest
```

## 📝 API Documentation

Full API documentation will be available at:
- Swagger UI: `/api/documentation`
- Postman Collection: Available in `/docs/`

## 🔄 Version Control

- **Git**: Version control
- **Branches**: 
  - `main`: Production code
  - `develop`: Development branch
  - `feature/*`: Feature branches

## 📞 Support

For technical support or questions:
- Email: tech@marawater.com
- Documentation: `/docs/api`
- Issues: GitHub repository

## 📄 License

This project is proprietary software for MARA-WATER Ltd.

---

**MARA-WATER Management System** - Built with Laravel 10
