# MARA-WATER Deployment Guide

## 🚀 Production Deployment

### Prerequisites
- Ubuntu 22.04 LTS server
- 4+ CPU cores, 8GB+ RAM, 100GB+ SSD
- Domain name with DNS configured

### Quick Deployment

#### 1. Server Setup
```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y nginx php8.2-fpm mysql-server redis-server nodejs npm git unzip

# Configure firewall
sudo ufw allow 'Nginx Full'
sudo ufw allow OpenSSH
sudo ufw enable
```

#### 2. Database Setup
```bash
# Secure MySQL
sudo mysql_secure_installation

# Create database and user
sudo mysql -u root -p
```

```sql
CREATE DATABASE mara_water CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mara_water_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON mara_water.* TO 'mara_water_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

#### 3. Application Deployment
```bash
# Clone repository
sudo mkdir -p /var/www/mara-water
sudo chown $USER:$USER /var/www/mara-water
cd /var/www/mara-water
git clone https://github.com/JimAlexLabs/MARA-WATER1.git .

# Setup backend
cd backend/mara-water-api
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate

# Configure .env for production
nano .env
```

**Production .env:**
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mara_water
DB_USERNAME=mara_water_user
DB_PASSWORD=strong_password_here

CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

```bash
# Import database
mysql -u mara_water_user -p mara_water < mara_water_complete_all_modules.sql

# Set permissions
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

#### 4. Frontend Build
```bash
# Setup frontend
cd ../../frontend/mara-water-web
npm ci --production
npm run build

# Copy to web server
sudo cp -r build/* /var/www/mara-water/public/
```

#### 5. Nginx Configuration
```bash
# Create Nginx config
sudo nano /etc/nginx/sites-available/mara-water
```

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/mara-water/public;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location /api/ {
        proxy_pass http://127.0.0.1:8000;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

```bash
# Enable site
sudo ln -s /etc/nginx/sites-available/mara-water /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

#### 6. SSL Certificate
```bash
# Install Certbot
sudo apt install -y certbot python3-certbot-nginx

# Obtain SSL certificate
sudo certbot --nginx -d your-domain.com

# Auto-renewal
sudo crontab -e
# Add: 0 12 * * * /usr/bin/certbot renew --quiet
```

#### 7. Process Management
```bash
# Create systemd service
sudo nano /etc/systemd/system/mara-water.service
```

```ini
[Unit]
Description=MARA-WATER Laravel Application
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/mara-water/backend/mara-water-api
ExecStart=/usr/bin/php artisan serve --host=127.0.0.1 --port=8000
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

```bash
# Start service
sudo systemctl daemon-reload
sudo systemctl enable mara-water
sudo systemctl start mara-water
```

### Monitoring & Maintenance

#### Health Check
```bash
# Create health check script
sudo nano /var/www/mara-water/health-check.sh
```

```bash
#!/bin/bash
curl -f http://127.0.0.1:8000/api/health > /dev/null 2>&1 || echo "Application down"
mysqladmin ping -h localhost -u mara_water_user -p'password' > /dev/null 2>&1 || echo "Database down"
redis-cli ping > /dev/null 2>&1 || echo "Redis down"
```

#### Backup Script
```bash
# Create backup script
sudo nano /var/www/mara-water/backup.sh
```

```bash
#!/bin/bash
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u mara_water_user -p mara_water > /var/backups/db_backup_$DATE.sql
gzip /var/backups/db_backup_$DATE.sql
find /var/backups -name "db_backup_*.sql.gz" -mtime +30 -delete
```

#### Scheduled Tasks
```bash
# Add to crontab
sudo crontab -e
```

Add these lines:
```
0 2 * * * /var/www/mara-water/backup.sh
*/5 * * * * /var/www/mara-water/health-check.sh
```

### Security Hardening

#### Firewall
```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow ssh
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

#### Fail2ban
```bash
sudo apt install -y fail2ban
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

### Troubleshooting

#### Common Issues
1. **502 Bad Gateway**: Check PHP-FPM status and logs
2. **Database Connection**: Verify MySQL service and credentials
3. **High Memory**: Restart PHP-FPM and check processes

#### Log Locations
- Laravel logs: `/var/www/mara-water/backend/mara-water-api/storage/logs/`
- Nginx logs: `/var/log/nginx/`
- PHP-FPM logs: `/var/log/php8.2-fpm.log`
- MySQL logs: `/var/log/mysql/`

### Performance Optimization

#### PHP-FPM Tuning
```bash
sudo nano /etc/php/8.2/fpm/pool.d/www.conf
```

```ini
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

#### MySQL Tuning
```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

```ini
[mysqld]
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
max_connections = 200
```

---

**Deployment Version**: 1.0.0  
**Status**: Production Ready ✅
