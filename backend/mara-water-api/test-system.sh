#!/bin/bash

echo "🧪 MARA-WATER System Test"
echo "========================="

# Test MySQL connection
echo "Testing MySQL connection..."
if /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
    echo "✅ MySQL is running"
else
    echo "❌ MySQL is not running"
    exit 1
fi

# Test database exists
echo "Testing database..."
if /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "USE mara_water;" >/dev/null 2>&1; then
    echo "✅ Database 'mara_water' exists"
else
    echo "❌ Database 'mara_water' does not exist"
    exit 1
fi

# Test Laravel configuration
echo "Testing Laravel configuration..."
if php artisan config:clear >/dev/null 2>&1; then
    echo "✅ Laravel configuration is valid"
else
    echo "❌ Laravel configuration error"
    exit 1
fi

# Test database connection from Laravel
echo "Testing Laravel database connection..."
if php artisan migrate:status >/dev/null 2>&1; then
    echo "✅ Laravel can connect to database"
else
    echo "❌ Laravel cannot connect to database"
    exit 1
fi

# Test if ports are available
echo "Testing port availability..."
if ! lsof -i :8005 >/dev/null 2>&1; then
    echo "✅ Port 8005 is available"
else
    echo "❌ Port 8005 is in use"
fi

if ! lsof -i :3005 >/dev/null 2>&1; then
    echo "✅ Port 3005 is available"
else
    echo "❌ Port 3005 is in use"
fi

echo ""
echo "🎉 System test completed successfully!"
echo "You can now run: ./start-mara-water.sh"
