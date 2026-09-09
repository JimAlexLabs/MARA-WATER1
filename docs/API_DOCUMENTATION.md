# MARA-WATER API Documentation

## 🌐 API Overview

### Base Information
- **Base URL**: `http://localhost:8005/api/v1`
- **Content Type**: `application/json`
- **Authentication**: Bearer Token (Laravel Sanctum)
- **Version**: v1.0.0

### Authentication
All protected endpoints require a valid Bearer token in the Authorization header:
```http
Authorization: Bearer {token}
```

## 🔐 Authentication Endpoints

### Login
Authenticate user and receive access token.

```http
POST /auth/login
```

**Request Body:**
```json
{
    "email": "director@marawater.com",
    "password": "password"
}
```

**Response (200):**
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
            "full_name": "Managing Director",
            "phone": "+254700000000",
            "avatar_url": null,
            "status": "active",
            "role": {
                "id": "550e8400-e29b-41d4-a716-446655440001",
                "code": "ADMIN",
                "name": "Director / Admin"
            },
            "department": {
                "id": "550e8400-e29b-41d4-a716-446655440101",
                "code": "ADMIN",
                "name": "Administration"
            }
        },
        "token": "2|QEXFV79RTqyra7TJ5mw6XWl5lX4IPSaiVz325mfI034d9d15",
        "token_type": "Bearer",
        "expires_in": 2592000
    }
}
```

**Error Response (422):**
```json
{
    "message": "The provided credentials are incorrect.",
    "errors": {
        "email": ["The provided credentials are incorrect."]
    }
}
```

### Logout
Invalidate current user session.

```http
POST /auth/logout
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response (200):**
```json
{
    "success": true,
    "message": "Logged out successfully"
}
```

### Get Profile
Retrieve current user profile information.

```http
GET /auth/profile
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response (200):**
```json
{
    "success": true,
    "data": {
        "user": {
            "id": "550e8400-e29b-41d4-a716-446655440201",
            "email": "director@marawater.com",
            "first_name": "Managing",
            "last_name": "Director",
            "full_name": "Managing Director",
            "phone": "+254700000000",
            "avatar_url": null,
            "status": "active",
            "role": {
                "id": "550e8400-e29b-41d4-a716-446655440001",
                "code": "ADMIN",
                "name": "Director / Admin"
            },
            "department": {
                "id": "550e8400-e29b-41d4-a716-446655440101",
                "code": "ADMIN",
                "name": "Administration"
            }
        }
    }
}
```

### Update Profile
Update current user profile information.

```http
PUT /auth/profile
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "first_name": "Managing",
    "last_name": "Director",
    "phone": "+254700000000"
}
```

**Response (200):**
```json
{
    "success": true,
    "message": "Profile updated successfully",
    "data": {
        "user": {
            "id": "550e8400-e29b-41d4-a716-446655440201",
            "email": "director@marawater.com",
            "first_name": "Managing",
            "last_name": "Director",
            "full_name": "Managing Director",
            "phone": "+254700000000",
            "avatar_url": null,
            "status": "active"
        }
    }
}
```

## 📊 Dashboard Endpoints

### Health Check
Check API health and status.

```http
GET /health
```

**Response (200):**
```json
{
    "status": "healthy",
    "timestamp": "2025-08-30T22:43:07.068648Z",
    "version": "1.0.0"
}
```

### Dashboard Overview
Get dashboard summary data.

```http
GET /dashboard
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Response (200):**
```json
{
    "success": true,
    "data": {
        "summary": {
            "total_users": 15,
            "active_users": 12,
            "total_orders": 245,
            "pending_orders": 23,
            "total_batches": 89,
            "active_batches": 12,
            "quality_tests_today": 8,
            "failed_tests_today": 1
        },
        "recent_activity": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440301",
                "type": "water_test",
                "description": "New water test recorded",
                "user": "John Doe",
                "timestamp": "2025-08-30T10:30:00Z"
            }
        ],
        "alerts": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440302",
                "type": "quality_alert",
                "message": "pH level out of range",
                "severity": "high",
                "timestamp": "2025-08-30T09:15:00Z"
            }
        ]
    }
}
```

## 🧪 Quality Assurance Endpoints

### Get Water Tests
Retrieve water quality test records.

```http
GET /qa/water-tests
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number for pagination
- `limit` (optional): Number of records per page (default: 15)
- `status` (optional): Filter by status (pending, pass, fail)
- `test_type` (optional): Filter by test type (baseline, random, retest)
- `date_from` (optional): Filter from date (YYYY-MM-DD)
- `date_to` (optional): Filter to date (YYYY-MM-DD)

**Response (200):**
```json
{
    "success": true,
    "data": {
        "water_tests": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440401",
                "test_type": "baseline",
                "recorded_at": "2025-08-30T08:00:00Z",
                "ph": 7.2,
                "tds": 150.5,
                "chlorine": 0.5,
                "unit_notes": "Normal readings",
                "location_text": "Main production line",
                "status": "pass",
                "recorded_by": {
                    "id": "550e8400-e29b-41d4-a716-446655440201",
                    "name": "John Doe"
                },
                "ric_verified_by": {
                    "id": "550e8400-e29b-41d4-a716-446655440202",
                    "name": "Jane Smith"
                },
                "created_at": "2025-08-30T08:00:00Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 45,
            "last_page": 3
        }
    }
}
```

### Create Water Test
Create a new water quality test record.

```http
POST /qa/water-tests
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "test_type": "baseline",
    "recorded_at": "2025-08-30T08:00:00Z",
    "ph": 7.2,
    "tds": 150.5,
    "chlorine": 0.5,
    "unit_notes": "Normal readings",
    "location_text": "Main production line",
    "warehouse_id": "550e8400-e29b-41d4-a716-446655440501"
}
```

**Response (201):**
```json
{
    "success": true,
    "message": "Water test created successfully",
    "data": {
        "water_test": {
            "id": "550e8400-e29b-41d4-a716-446655440401",
            "test_type": "baseline",
            "recorded_at": "2025-08-30T08:00:00Z",
            "ph": 7.2,
            "tds": 150.5,
            "chlorine": 0.5,
            "unit_notes": "Normal readings",
            "location_text": "Main production line",
            "status": "pending",
            "created_at": "2025-08-30T08:00:00Z"
        }
    }
}
```

### Update Water Test
Update an existing water test record.

```http
PUT /qa/water-tests/{id}
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "ph": 7.3,
    "tds": 155.0,
    "chlorine": 0.6,
    "unit_notes": "Updated readings"
}
```

**Response (200):**
```json
{
    "success": true,
    "message": "Water test updated successfully",
    "data": {
        "water_test": {
            "id": "550e8400-e29b-41d4-a716-446655440401",
            "ph": 7.3,
            "tds": 155.0,
            "chlorine": 0.6,
            "unit_notes": "Updated readings",
            "updated_at": "2025-08-30T09:00:00Z"
        }
    }
}
```

### Verify Water Test
Verify a water test (RIC role only).

```http
POST /qa/water-tests/{id}/verify
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "status": "pass",
    "notes": "Test results verified and approved"
}
```

**Response (200):**
```json
{
    "success": true,
    "message": "Water test verified successfully",
    "data": {
        "water_test": {
            "id": "550e8400-e29b-41d4-a716-446655440401",
            "status": "pass",
            "ric_verified_by": {
                "id": "550e8400-e29b-41d4-a716-446655440202",
                "name": "Jane Smith"
            },
            "verified_at": "2025-08-30T09:30:00Z"
        }
    }
}
```

## 🏭 Production Endpoints

### Get Batches
Retrieve production batch records.

```http
GET /production/batches
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number for pagination
- `limit` (optional): Number of records per page (default: 15)
- `status` (optional): Filter by status (open, in_progress, closed)
- `sku_id` (optional): Filter by SKU
- `date_from` (optional): Filter from manufacture date
- `date_to` (optional): Filter to manufacture date

**Response (200):**
```json
{
    "success": true,
    "data": {
        "batches": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440601",
                "code": "BATCH-2025-001",
                "sku": {
                    "id": "550e8400-e29b-41d4-a716-446655440701",
                    "code": "SKU-001",
                    "name": "500ml Bottled Water"
                },
                "manufacture_date": "2025-08-30",
                "expiry_date": "2026-08-30",
                "planned_qty": 1000,
                "status": "open",
                "opened_by": {
                    "id": "550e8400-e29b-41d4-a716-446655440201",
                    "name": "John Doe"
                },
                "created_at": "2025-08-30T08:00:00Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 23,
            "last_page": 2
        }
    }
}
```

### Create Batch
Create a new production batch.

```http
POST /production/batches
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "sku_id": "550e8400-e29b-41d4-a716-446655440701",
    "manufacture_date": "2025-08-30",
    "expiry_date": "2026-08-30",
    "planned_qty": 1000
}
```

**Response (201):**
```json
{
    "success": true,
    "message": "Batch created successfully",
    "data": {
        "batch": {
            "id": "550e8400-e29b-41d4-a716-446655440601",
            "code": "BATCH-2025-001",
            "sku_id": "550e8400-e29b-41d4-a716-446655440701",
            "manufacture_date": "2025-08-30",
            "expiry_date": "2026-08-30",
            "planned_qty": 1000,
            "status": "open",
            "created_at": "2025-08-30T08:00:00Z"
        }
    }
}
```

### Update Batch Status
Update batch status (open, in_progress, closed).

```http
PUT /production/batches/{id}/status
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "status": "in_progress"
}
```

**Response (200):**
```json
{
    "success": true,
    "message": "Batch status updated successfully",
    "data": {
        "batch": {
            "id": "550e8400-e29b-41d4-a716-446655440601",
            "status": "in_progress",
            "updated_at": "2025-08-30T09:00:00Z"
        }
    }
}
```

## 📦 Inventory Endpoints

### Get Stock Items
Retrieve current inventory levels.

```http
GET /inventory/stock-items
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number for pagination
- `limit` (optional): Number of records per page (default: 15)
- `item_type` (optional): Filter by item type (material, sku)
- `warehouse_id` (optional): Filter by warehouse
- `low_stock` (optional): Filter items with low stock (true/false)

**Response (200):**
```json
{
    "success": true,
    "data": {
        "stock_items": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440801",
                "item_type": "sku",
                "sku": {
                    "id": "550e8400-e29b-41d4-a716-446655440701",
                    "code": "SKU-001",
                    "name": "500ml Bottled Water"
                },
                "warehouse": {
                    "id": "550e8400-e29b-41d4-a716-446655440501",
                    "name": "Main Warehouse"
                },
                "qty": 500,
                "unit": "bottles",
                "last_updated": "2025-08-30T08:00:00Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 67,
            "last_page": 5
        }
    }
}
```

### Get Stock Movements
Retrieve inventory transaction history.

```http
GET /inventory/stock-moves
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number for pagination
- `limit` (optional): Number of records per page (default: 15)
- `move_type` (optional): Filter by move type (grn, issue, produce, adjust, transfer, return)
- `item_type` (optional): Filter by item type (material, sku)
- `date_from` (optional): Filter from date
- `date_to` (optional): Filter to date

**Response (200):**
```json
{
    "success": true,
    "data": {
        "stock_moves": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440901",
                "move_type": "produce",
                "item_type": "sku",
                "sku": {
                    "id": "550e8400-e29b-41d4-a716-446655440701",
                    "name": "500ml Bottled Water"
                },
                "qty": 1000,
                "unit": "bottles",
                "unit_cost": 0.50,
                "ref_entity": "batch",
                "ref_id": "550e8400-e29b-41d4-a716-446655440601",
                "moved_by": {
                    "id": "550e8400-e29b-41d4-a716-446655440201",
                    "name": "John Doe"
                },
                "created_at": "2025-08-30T08:00:00Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 234,
            "last_page": 16
        }
    }
}
```

## 🛒 Sales Endpoints

### Get Orders
Retrieve customer orders.

```http
GET /sales/orders
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number for pagination
- `limit` (optional): Number of records per page (default: 15)
- `status` (optional): Filter by status (draft, confirmed, dispatched, delivered, partially_returned, cancelled)
- `customer_id` (optional): Filter by customer
- `sales_officer_id` (optional): Filter by sales officer
- `date_from` (optional): Filter from order date
- `date_to` (optional): Filter to order date

**Response (200):**
```json
{
    "success": true,
    "data": {
        "orders": [
            {
                "id": "550e8400-e29b-41d4-a716-446655441001",
                "order_no": "ORD-2025-001",
                "customer": {
                    "id": "550e8400-e29b-41d4-a716-446655441101",
                    "name": "ABC Company Ltd",
                    "phone": "+254700000001"
                },
                "sales_officer": {
                    "id": "550e8400-e29b-41d4-a716-446655440201",
                    "name": "John Doe"
                },
                "status": "confirmed",
                "order_date": "2025-08-30",
                "requested_date": "2025-08-31",
                "total_amount": 5000.00,
                "created_at": "2025-08-30T08:00:00Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 45,
            "last_page": 3
        }
    }
}
```

### Create Order
Create a new customer order.

```http
POST /sales/orders
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "customer_id": "550e8400-e29b-41d4-a716-446655441101",
    "order_date": "2025-08-30",
    "requested_date": "2025-08-31",
    "items": [
        {
            "sku_id": "550e8400-e29b-41d4-a716-446655440701",
            "qty": 100,
            "unit_price": 50.00
        }
    ]
}
```

**Response (201):**
```json
{
    "success": true,
    "message": "Order created successfully",
    "data": {
        "order": {
            "id": "550e8400-e29b-41d4-a716-446655441001",
            "order_no": "ORD-2025-001",
            "customer_id": "550e8400-e29b-41d4-a716-446655441101",
            "status": "draft",
            "order_date": "2025-08-30",
            "requested_date": "2025-08-31",
            "total_amount": 5000.00,
            "created_at": "2025-08-30T08:00:00Z"
        }
    }
}
```

### Update Order Status
Update order status.

```http
PUT /sales/orders/{id}/status
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "status": "confirmed"
}
```

**Response (200):**
```json
{
    "success": true,
    "message": "Order status updated successfully",
    "data": {
        "order": {
            "id": "550e8400-e29b-41d4-a716-446655441001",
            "status": "confirmed",
            "updated_at": "2025-08-30T09:00:00Z"
        }
    }
}
```

## 👥 User Management Endpoints

### Get Users
Retrieve system users (Admin only).

```http
GET /users
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `page` (optional): Page number for pagination
- `limit` (optional): Number of records per page (default: 15)
- `role_id` (optional): Filter by role
- `department_id` (optional): Filter by department
- `status` (optional): Filter by status (active, inactive)

**Response (200):**
```json
{
    "success": true,
    "data": {
        "users": [
            {
                "id": "550e8400-e29b-41d4-a716-446655440201",
                "email": "director@marawater.com",
                "first_name": "Managing",
                "last_name": "Director",
                "full_name": "Managing Director",
                "phone": "+254700000000",
                "status": "active",
                "role": {
                    "id": "550e8400-e29b-41d4-a716-446655440001",
                    "code": "ADMIN",
                    "name": "Director / Admin"
                },
                "department": {
                    "id": "550e8400-e29b-41d4-a716-446655440101",
                    "code": "ADMIN",
                    "name": "Administration"
                },
                "last_login_at": "2025-08-30T08:00:00Z",
                "created_at": "2025-08-30T08:00:00Z"
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 15,
            "total": 15,
            "last_page": 1
        }
    }
}
```

### Create User
Create a new system user (Admin only).

```http
POST /users
```

**Headers:**
```http
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**
```json
{
    "email": "qa@marawater.com",
    "password": "password123",
    "first_name": "John",
    "last_name": "Doe",
    "phone": "+254700000002",
    "role_id": "550e8400-e29b-41d4-a716-446655440002",
    "department_id": "550e8400-e29b-41d4-a716-446655440102"
}
```

**Response (201):**
```json
{
    "success": true,
    "message": "User created successfully",
    "data": {
        "user": {
            "id": "550e8400-e29b-41d4-a716-446655440203",
            "email": "qa@marawater.com",
            "first_name": "John",
            "last_name": "Doe",
            "full_name": "John Doe",
            "phone": "+254700000002",
            "status": "active",
            "role": {
                "id": "550e8400-e29b-41d4-a716-446655440002",
                "code": "QA",
                "name": "Quality Assurance"
            },
            "department": {
                "id": "550e8400-e29b-41d4-a716-446655440102",
                "code": "QA",
                "name": "Quality Assurance"
            },
            "created_at": "2025-08-30T08:00:00Z"
        }
    }
}
```

## 📊 Reporting Endpoints

### Get Quality Report
Generate quality assurance report.

```http
GET /reports/quality
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `date_from` (required): Start date (YYYY-MM-DD)
- `date_to` (required): End date (YYYY-MM-DD)
- `format` (optional): Report format (json, csv, pdf)

**Response (200):**
```json
{
    "success": true,
    "data": {
        "report": {
            "period": {
                "from": "2025-08-01",
                "to": "2025-08-30"
            },
            "summary": {
                "total_tests": 240,
                "passed_tests": 235,
                "failed_tests": 5,
                "compliance_rate": 97.92
            },
            "daily_breakdown": [
                {
                    "date": "2025-08-30",
                    "total_tests": 8,
                    "passed_tests": 7,
                    "failed_tests": 1,
                    "compliance_rate": 87.5
                }
            ],
            "failed_tests_details": [
                {
                    "id": "550e8400-e29b-41d4-a716-446655440401",
                    "test_type": "baseline",
                    "recorded_at": "2025-08-30T08:00:00Z",
                    "ph": 9.2,
                    "tds": 600.0,
                    "chlorine": 0.8,
                    "status": "fail",
                    "recorded_by": "John Doe"
                }
            ]
        }
    }
}
```

### Get Production Report
Generate production report.

```http
GET /reports/production
```

**Headers:**
```http
Authorization: Bearer {token}
```

**Query Parameters:**
- `date_from` (required): Start date (YYYY-MM-DD)
- `date_to` (required): End date (YYYY-MM-DD)
- `sku_id` (optional): Filter by SKU
- `format` (optional): Report format (json, csv, pdf)

**Response (200):**
```json
{
    "success": true,
    "data": {
        "report": {
            "period": {
                "from": "2025-08-01",
                "to": "2025-08-30"
            },
            "summary": {
                "total_batches": 89,
                "completed_batches": 85,
                "total_planned_qty": 89000,
                "total_produced_qty": 87500,
                "efficiency_rate": 98.31
            },
            "sku_breakdown": [
                {
                    "sku": {
                        "id": "550e8400-e29b-41d4-a716-446655440701",
                        "name": "500ml Bottled Water"
                    },
                    "batches": 45,
                    "planned_qty": 45000,
                    "produced_qty": 44200,
                    "efficiency_rate": 98.22
                }
            ],
            "daily_production": [
                {
                    "date": "2025-08-30",
                    "batches": 3,
                    "planned_qty": 3000,
                    "produced_qty": 2950,
                    "efficiency_rate": 98.33
                }
            ]
        }
    }
}
```

## 🔧 Error Handling

### Standard Error Response Format
All API endpoints return errors in a consistent format:

```json
{
    "message": "Error description",
    "errors": {
        "field_name": ["Field-specific error message"]
    },
    "exception": "Exception class name",
    "file": "File path where error occurred",
    "line": 123,
    "trace": [
        {
            "file": "File path",
            "line": 123,
            "function": "Function name",
            "class": "Class name",
            "type": "->"
        }
    ]
}
```

### Common HTTP Status Codes
- `200` - Success
- `201` - Created
- `400` - Bad Request
- `401` - Unauthorized
- `403` - Forbidden
- `404` - Not Found
- `422` - Validation Error
- `500` - Internal Server Error

### Validation Errors
When request validation fails, the API returns a 422 status with field-specific errors:

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "email": [
            "The email field is required.",
            "The email must be a valid email address."
        ],
        "password": [
            "The password field is required.",
            "The password must be at least 8 characters."
        ]
    }
}
```

## 🔒 Security Considerations

### Authentication
- All protected endpoints require valid Bearer token
- Tokens expire after 30 days
- Tokens are invalidated on logout
- Failed authentication returns 401 status

### Authorization
- Role-based access control (RBAC)
- Permission-based endpoint access
- User can only access data within their scope
- Admin users have full system access

### Rate Limiting
- API requests are rate-limited to prevent abuse
- Rate limits are applied per user/IP
- Exceeding limits returns 429 status

### Data Validation
- All input data is validated
- SQL injection protection via prepared statements
- XSS protection via output encoding
- File upload validation and scanning

## 📱 Mobile API Considerations

### Offline Support
- API supports offline data synchronization
- Conflict resolution for concurrent updates
- Data versioning for conflict detection

### Push Notifications
- WebSocket support for real-time updates
- Push notification endpoints for mobile apps
- Notification preferences per user

### File Upload
- Multipart form data support
- Image compression and optimization
- Secure file storage with access controls

---

**API Version**: 1.0.0  
**Last Updated**: August 30, 2025  
**Total Endpoints**: 50+  
**Status**: Production Ready ✅
