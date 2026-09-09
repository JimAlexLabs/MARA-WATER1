#!/bin/bash

echo "🧪 Testing MARA-WATER System"
echo "============================"

# Test MySQL
echo "Testing MySQL..."
if /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "SELECT 1;" >/dev/null 2>&1; then
    echo "✅ MySQL is running"
else
    echo "❌ MySQL is not running"
    exit 1
fi

# Test database
echo "Testing database..."
if /Applications/XAMPP/xamppfiles/bin/mysql -u root -e "USE mara_water;" >/dev/null 2>&1; then
    echo "✅ Database exists"
else
    echo "❌ Database does not exist"
    exit 1
fi

# Test user exists
USER_COUNT=$(/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "USE mara_water; SELECT COUNT(*) FROM users;" 2>/dev/null | tail -1)
if [ "$USER_COUNT" -gt 0 ]; then
    echo "✅ Database has $USER_COUNT users"
else
    echo "❌ No users in database"
    exit 1
fi

# Test Laravel
echo "Testing Laravel..."
if php artisan config:clear >/dev/null 2>&1; then
    echo "✅ Laravel configuration is valid"
else
    echo "❌ Laravel configuration error"
    exit 1
fi

# Test database connection
if php artisan migrate:status >/dev/null 2>&1; then
    echo "✅ Laravel can connect to database"
else
    echo "❌ Laravel cannot connect to database"
    exit 1
fi

echo ""
echo "🎉 All tests passed! Starting servers..."
echo ""

# Start Laravel
echo "Starting Laravel server..."
php artisan serve --port=8005 --host=0.0.0.0 > laravel.log 2>&1 &
LARAVEL_PID=$!

sleep 5

if curl -s http://localhost:8005 >/dev/null 2>&1; then
    echo "✅ Laravel server started on http://localhost:8005"
else
    echo "❌ Laravel server failed to start"
    tail -10 laravel.log
    exit 1
fi

# Start React
echo "Starting React server..."
cd ../frontend/mara-water-web
PORT=3005 npm start > react.log 2>&1 &
REACT_PID=$!

sleep 15

if curl -s http://localhost:3005 >/dev/null 2>&1; then
    echo "✅ React server started on http://localhost:3005"
else
    echo "❌ React server failed to start"
    tail -10 react.log
    exit 1
fi

echo ""
echo "🎉 MARA-WATER System is running!"
echo "Frontend: http://localhost:3005"
echo "Backend:  http://localhost:8005"
echo "Login:    director@marawater.com / Admin@2024"
echo ""
echo "Press Ctrl+C to stop"

# Save PIDs
cd ../../backend/mara-water-api
echo $LARAVEL_PID > .laravel.pid
echo $REACT_PID > .react.pid

# Wait for interrupt
trap 'echo "Stopping servers..."; kill $LARAVEL_PID $REACT_PID 2>/dev/null; exit' INT
wait
