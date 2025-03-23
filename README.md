# KVMDash Backend

<table style="border-collapse: collapse; width: 100%;">
    <tr>
        <td style="width: 150px; padding: 10px; vertical-align: middle;">
            <img src="https://github.com/KvmDash/.github/raw/main/profile/kvmdash.svg" alt="KvmDash Logo" style="max-width: 100%;">
        </td>
        <td style="padding: 10px; vertical-align: middle;">
            KVMDash is a web application that enables the management of Virtual Machines (VMs) on Linux systems. 
            This is the backend of the application, based on Symfony 7 and API Platform.</td>
    </tr>
</table>

## System Requirements

* PHP 8.2 or newer
* Composer 2.x
* MySQL/MariaDB, PostgreSQL or SQLite
* OpenSSL for JWT key generation

## Installation & Configuration

### 1. Clone Repository

```bash
git clone https://github.com/KvmDash/KvmDash.back.git kvmdash-backend
cd kvmdash-backend
```

### 2. Configure Environment Variables

```bash
# Create .env.local
cp .env .env.local
```

Edit `.env.local` and adjust the following settings:

```env
# Development environment
APP_ENV=dev
APP_SECRET=YourSecretKey

# Choose one of the following database options and uncomment it:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
# or
# DATABASE_URL="mysql://user:password@127.0.0.1:3306/kvmdash"
# or
# DATABASE_URL="postgresql://user:password@127.0.0.1:5432/kvmdash?serverVersion=15&charset=utf8"

# JWT Configuration
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=YourJWTPassphrase
```

### 3. Install Dependencies

```bash
composer install
```

### 4. Generate JWT Keys

```bash
mkdir -p config/jwt
openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout
```

### 5. Setup Database

```bash
# Create database schema
php bin/console doctrine:schema:create
```

### 6. Create Admin User

```bash
php bin/console app:create-user "admin@example.com" "YourPassword"
```

### 7. Start Development Server

```bash
# With SSL on port 8000
symfony server:start --port=8000

# Alternatively with PHP's built-in server (not for production!)
php -S localhost:8000 -t public/
```

## API Documentation

The API documentation is available after starting the server at:
- Swagger UI: https://localhost:8000/api/docs

## Production Deployment

For production use:

1. Set `APP_ENV=prod` in `.env.local`
2. Optimize the autoloader:
```bash
composer dump-autoload --optimize --no-dev --classmap-authoritative
```
3. Clear the cache:
```bash
php bin/console cache:clear --env=prod
```

## Note

This backend is required by the KVMDash Frontend. Make sure the CORS settings in `.env.local` are configured correctly.
