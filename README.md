# PathForge – Backend

## Getting Started

Thank you for purchasing **PathForge**. This guide explains how to install, configure, and run the Laravel backend application.

---

## 1. Requirements

Before installing the application, make sure your server or local development environment has the following installed:

* PHP 8.2 or higher
* Composer 2.x
* MySQL 8.0+ or MariaDB
* Git
* Node.js 18+ and npm (required if frontend assets are managed from the backend environment)
* Docker and Docker Compose (optional, for Docker installation)

### Required PHP Extensions

Make sure the following PHP extensions are enabled:

* BCMath
* Ctype
* cURL
* DOM
* Fileinfo
* JSON
* Mbstring
* OpenSSL
* PDO
* PDO MySQL
* Tokenizer
* XML
* Zip

---

# 2. Installation

## Step 1 – Extract the Backend

Extract the backend package to your preferred project directory.

For example:

```bash
cd /var/www/
```

---

## Step 2 – Install PHP Dependencies

Open a terminal in the backend directory and run:

```bash
composer install --no-dev --optimize-autoloader
```

For local development, you can use:

```bash
composer install
```

---

## Step 3 – Configure Environment

Copy the example environment file:

```bash
cp .env.example .env
```

Open the `.env` file and configure your application settings.

### Application

```env
APP_NAME="PathForge"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
```

Generate the Laravel application key:

```bash
php artisan key:generate
```

---

# 3. Database Configuration

Create a MySQL database and database user.

Then update the following values in your `.env` file:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

> Replace the placeholder values with the credentials of your own database.

Run the database migrations:

```bash
php artisan migrate
```

If the package includes seed data, run:

```bash
php artisan db:seed
```

You can also run both commands together:

```bash
php artisan migrate --seed
```

---

# 4. Storage Configuration

Create the Laravel storage link:

```bash
php artisan storage:link
```

Make sure the following directories have appropriate write permissions:

```text
storage/
bootstrap/cache/
```

For Linux servers, you may use:

```bash
chmod -R 775 storage bootstrap/cache
```

---

# 5. Cache and Optimization

For production environments, run:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

To clear the application cache:

```bash
php artisan optimize:clear
```

---

# 6. Run the Application

## Local Development

For local development, start the Laravel development server:

```bash
php artisan serve
```

The application will normally be available at:

```text
http://127.0.0.1:8000
```

---

# 7. Docker Installation

Docker is optional. If you prefer to run the backend using Docker, make sure Docker and Docker Compose are installed.

### Start the containers

```bash
docker compose up -d
```

### Rebuild the containers

```bash
docker compose up -d --build
```

### Stop the containers

```bash
docker compose down
```

### View running containers

```bash
docker compose ps
```

### View application logs

```bash
docker compose logs -f
```

### Access the application container

Use the service name defined in your `docker-compose.yml` file:

```bash
docker compose exec app bash
```

> The exact container/service names may vary depending on your Docker Compose configuration.

---

# 8. API Configuration

The backend provides the API used by the PathForge frontend.

Set the application URL in `.env`:

```env
APP_URL=https://your-domain.com
```

If the application is deployed under a different API domain, configure the frontend to use the corresponding API URL.

Example:

```text
https://api.your-domain.com
```

---

# 9. Mail Configuration

If email functionality is enabled, configure your SMTP provider in `.env`.

Example:

```env
MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_USERNAME=your-email@example.com
MAIL_PASSWORD=your-email-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

> Use the SMTP credentials provided by your email service provider.

---

# 10. OpenAI Configuration

If the AI features are enabled, add your OpenAI API credentials to the `.env` file according to the variables provided in `.env.example`.

Example:

```env
OPENAI_API_KEY=your_openai_api_key
```

> Never publish your real API key in the source code or `.env.example` file.

---

# 11. Firebase Configuration

If Firebase functionality is enabled, configure the Firebase credentials according to the variables provided in `.env.example`.

Do not upload private Firebase credentials or production secrets to a public repository.

---

# 12. Stripe Configuration

If payment functionality is enabled, configure your Stripe credentials using the variables provided in `.env.example`.

For testing, use Stripe test-mode credentials.

Example:

```env
STRIPE_KEY=your_stripe_publishable_key
STRIPE_SECRET=your_stripe_secret_key
```

> Use your own Stripe account and credentials. Payment credentials are not included with the product.

---

# 13. Queue Configuration

If queued jobs are used, configure the queue connection in `.env`.

Example:

```env
QUEUE_CONNECTION=database
```

If using the database queue, make sure the required queue tables have been created.

Run:

```bash
php artisan queue:table
php artisan migrate
```

Start the worker:

```bash
php artisan queue:work
```

For production servers, it is recommended to run the queue worker using a process manager such as Supervisor.

---

# 14. Module Management

PathForge uses a modular application structure.

Modules can be enabled or disabled through their respective `module.json` configuration files.

Before disabling a module, make sure that no other application functionality depends on it.

---

# 15. Production Deployment

For production deployment, make sure:

```env
APP_ENV=production
APP_DEBUG=false
```

Then run:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan optimize
```

Configure your web server to point to Laravel's:

```text
public/
```

directory.

**Do not point the web server directly to the Laravel project root.**

---

# 16. Nginx

For Nginx deployment, configure the server root to the Laravel `public` directory.

Example:

```nginx
server {
    listen 80;
    server_name your-domain.com;

    root /var/www/pathforge/public;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

Adjust the PHP-FPM socket and project path according to your server configuration.

---

# 17. SSL / HTTPS

For production websites, HTTPS is strongly recommended.

You can use Let's Encrypt with Certbot to obtain a free SSL certificate.

After SSL is configured, update:

```env
APP_URL=https://your-domain.com
```

---

# 18. Troubleshooting

### Clear Laravel caches

```bash
php artisan optimize:clear
```

### Rebuild configuration cache

```bash
php artisan config:cache
```

### Check application logs

Laravel logs are located in:

```text
storage/logs/
```

### Permission problems

Make sure these directories are writable:

```text
storage/
bootstrap/cache/
```

### Database connection problems

Check:

* Database hostname
* Database name
* Database username
* Database password
* Database port
* MySQL service status

### Migration problems

Check the database configuration and run:

```bash
php artisan migrate:status
```

---

# 19. Security Recommendations

For production use:

* Set `APP_DEBUG=false`.
* Never upload the `.env` file to a public repository.
* Never share API keys or payment credentials.
* Use strong database passwords.
* Use HTTPS.
* Keep PHP, Laravel, and server packages updated.
* Restrict database access to trusted hosts.
* Configure appropriate file permissions.

---

# 20. Project Structure

The main Laravel directories include:

```text
app/
bootstrap/
config/
database/
modules/
public/
resources/
routes/
storage/
tests/
```

The Laravel `public/` directory should be the web server's document root.

---

# 21. Useful Artisan Commands

Clear all cached files:

```bash
php artisan optimize:clear
```

Run migrations:

```bash
php artisan migrate
```

Run seeders:

```bash
php artisan db:seed
```

Create storage link:

```bash
php artisan storage:link
```

Start local development server:

```bash
php artisan serve
```

Run queue worker:

```bash
php artisan queue:work
```

Check registered routes:

```bash
php artisan route:list
```

---

# 22. Support

If you experience installation or configuration problems, first review this documentation and the troubleshooting section.

When contacting support, please provide:

* Product version
* PHP version
* Laravel version
* Server operating system
* Installation method (Docker or manual)
* Exact error message
* Relevant Laravel log entry

**Please do not send passwords, API keys, payment credentials, or other private information.**

---

# 23. License

This product is distributed according to the license and purchase terms applicable to the marketplace from which it was purchased.

Third-party libraries, frameworks, fonts, icons, images, and other assets remain subject to their respective licenses.

For third-party credits and license information, please see the included `LICENSE`, `CREDITS`, or `THIRD-PARTY-LICENSES` files where applicable.

---

## Version

**Product:** PathForge
**Component:** Backend
**Framework:** Laravel
**Minimum PHP Version:** 8.2
**Documentation Version:** 1.0.0
