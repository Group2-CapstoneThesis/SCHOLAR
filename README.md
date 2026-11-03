# S.C.H.O.L.A.R.

**S.C.H.O.L.A.R.** (Streamlined Centralized Hub for Organizing Local Archives and Research) is a web-based repository for approved undergraduate theses at World Citi Colleges Quezon City. The Research, Development, and Publications Office (RDPO) manages the records, while the public can search and view published thesis metadata.

## Features

- RDPO administrator login with session timeouts, account status checks, and failed-login rate limiting
- Thesis record management: create, edit, publish/unpublish, and archive/restore
- Private manuscript PDF uploads and an RDPO-only PDF viewer
- PDF metadata autofill for the new-record form (reads the WCC title page, approval sheet, and abstract)
- RDPO search and status filters
- Public search and detail pages displaying thesis metadata only
- Audit log tracking logins, account status changes, and thesis record updates

## Tech Stack

- PHP 8.5.12
- Apache 2.4 & MySQL 8.4 (Laragon)
- Bootstrap 5.3.3
- Composer (`robmorgan/phinx`, `vlucas/phpdotenv`, `smalot/pdfparser`)

## Project Setup

### Prerequisites

1. Install [Laragon](https://laragon.org/) with Apache 2.4, MySQL 8.4, and PHP 8.5.12
2. Install [Composer](https://getcomposer.org/).

In PowerShell, point to Laragon's PHP 8.5.12 executable:

```powershell
$phpExe = 'C:\laragon\bin\php\php 8.5.12\php.exe'
```

### Installation

1. Navigate to the project directory:

```powershell
cd 'path\to\scholar'
```

2. Install Composer dependencies:

```powershell
composer install
```

3. Initialize the local database and `.env` file (for fresh installations):

```powershell
$env:SCHOLAR_DB_ADMIN_USER = 'root'
$env:SCHOLAR_DB_ADMIN_PASSWORD = ''
& $phpExe bin/init_database.php
Remove-Item Env:SCHOLAR_DB_ADMIN_USER, Env:SCHOLAR_DB_ADMIN_PASSWORD
```

   Alternatively, copy `.env.example` to `.env` and fill in your local database credentials.

4. Run database migrations and seed sample records:

```powershell
& $phpExe bin/console.php migrate
& $phpExe bin/console.php seed
```

5. Configure the Apache virtual host by copying `deploy/laragon-vhosts.conf` to `C:\laragon\etc\apache2\sites-enabled\scholar.conf` (adjust the `DocumentRoot` path if needed), then reload Apache in Laragon.

6. Create an RDPO administrator account (passwords must be 12 to 72 characters). Replace `my_admin` and `my_admin@example.invalid` with your desired username and email:

```powershell
$secure = Read-Host 'New RDPO password' -AsSecureString
$env:SCHOLAR_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
& $phpExe bin/console.php provision-admin <username> <email>
Remove-Item Env:SCHOLAR_ADMIN_PASSWORD
```

For example:
```powershell
& $phpExe bin/console.php provision-admin my_admin my_admin@example.invalid
```

## Usage

Open **http://scholar.localhost/** in your browser.

| Page | Access | Description |
| --- | --- | --- |
| `index.php` | Public | Search published theses by keywords, year, and program |
| `thesis.php?id=N` | Public | View published thesis metadata |
| `login.php` | RDPO | Sign in with username or email |
| `admin.php` | RDPO | Manage thesis records with search and status filters |
| `admin_thesis_form.php` | RDPO | Create or edit a thesis record (with optional PDF autofill) |
| `admin_thesis.php?id=N` | RDPO | View full record details, upload/view PDF, publish/archive, and view audit history |

### Account CLI Commands

Disable or re-enable an RDPO account from the terminal:

```powershell
& $phpExe bin/console.php disable-account my_admin
& $phpExe bin/console.php enable-account my_admin
```

### Running Tests

Test scripts are located in `tests/` (Apache and MySQL must be running for the `verify_*` scripts):

```powershell
& $phpExe tests/unit_testing.php
& $phpExe tests/unit_sessions.php
& $phpExe tests/unit_thesis_rules.php
& $phpExe tests/unit_pdf_rules.php
& $phpExe tests/unit_autofill_rules.php
& $phpExe tests/verify_user.php http://scholar.localhost
& $phpExe tests/verify_thesis_records.php http://scholar.localhost
& $phpExe tests/verify_public_search.php http://scholar.localhost
& $phpExe tests/verify_thesis_files.php http://scholar.localhost
& $phpExe tests/verify_autofill.php http://scholar.localhost
```
