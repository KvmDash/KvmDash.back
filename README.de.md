# Projektbeschreibung: KVMDash Backend

<table style="border-collapse: collapse; width: 100%;">
    <tr>
        <td style="width: 150px; padding: 10px; vertical-align: middle;">
            <img src="https://github.com/KvmDash/.github/raw/main/profile/kvmdash.svg" alt="KvmDash Logo" style="max-width: 100%;">
        </td>
        <td style="padding: 10px; vertical-align: middle;">
            KVMDash ist eine Webanwendung, die die Verwaltung von Virtual Machines (VMs) auf Linux-Systemen ermöglicht. 
            Dies ist das Backend der Anwendung, basierend auf Symfony 7 und API Platform.</td>
    </tr>
</table>

## Systemvoraussetzungen

* PHP 8.2 oder neuer
* Composer 2.x
* MySQL/MariaDB, PostgreSQL oder SQLite
* OpenSSL für JWT-Schlüsselgenerierung

## Installation & Konfiguration

### 1. Repository klonen

```bash
git clone https://github.com/KvmDash/KvmDash.back.git kvmdash-backend
cd kvmdash-backend
```

### 2. Umgebungsvariablen konfigurieren

```bash
# .env.local erstellen
cp .env .env.local
```

Bearbeiten Sie `.env.local` und passen Sie folgende Einstellungen an:

```env
# Entwicklungsumgebung
APP_ENV=dev
APP_SECRET=IhrGeheimesSecret

# Wählen Sie eine der folgenden Datenbankoptionen und entkommentieren Sie diese:
DATABASE_URL="sqlite:///%kernel.project_dir%/var/data.db"
# oder
# DATABASE_URL="mysql://user:password@127.0.0.1:3306/kvmdash"
# oder
# DATABASE_URL="postgresql://user:password@127.0.0.1:5432/kvmdash?serverVersion=15&charset=utf8"

# JWT Konfiguration
JWT_SECRET_KEY=%kernel.project_dir%/config/jwt/private.pem
JWT_PUBLIC_KEY=%kernel.project_dir%/config/jwt/public.pem
JWT_PASSPHRASE=IhrJWTPassphrase
```

### 3. Dependencies installieren

```bash
composer install
```

### 4. JWT-Schlüssel generieren

```bash
mkdir -p config/jwt
openssl genpkey -out config/jwt/private.pem -aes256 -algorithm rsa -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout
```

### 5. Datenbank einrichten

```bash
# Datenbank-Schema erstellen
php bin/console doctrine:schema:create
```

### 6. Admin-Benutzer anlegen

```bash
php bin/console app:create-user "admin@example.com" "IhrPasswort"
```

### 7. Entwicklungs-Server starten

```bash
# Mit SSL auf Port 8000
symfony server:start --port=8000

# Alternativ mit PHP's eingebautem Server (nicht für Produktion!)
php -S localhost:8000 -t public/
```

## API-Dokumentation

Die API-Dokumentation ist nach dem Start des Servers verfügbar unter:
- Swagger UI: https://localhost:8000/api/docs
- JSON-LD Kontext: https://localhost:8000/api/contexts/ApiResource
- OpenAPI Spezifikation: https://localhost:8000/api/docs.json



## Produktions-Deployment

Für den Produktionseinsatz:

1. Setzen Sie `APP_ENV=prod` in `.env.local`
2. Optimieren Sie den Autoloader:
```bash
composer dump-autoload --optimize --no-dev --classmap-authoritative
```
3. Leeren Sie den Cache:
```bash
php bin/console cache:clear --env=prod
```

## Hinweis

Dieses Backend wird vom KVMDash Frontend benötigt. Stellen Sie sicher, dass die CORS-Einstellungen in der `.env.local` korrekt konfiguriert sind.