# SET-SEF Evaluation System

## System Overview

This project is a web-based SET - SEF evaluation system for NORSU.
It supports SET (Student Evaluation for Teacher) and SET (Superior Evaluation for Faculty) evaluation workflows,
including evaluation scheduling, questionnaire management, result analytics, print-ready
reports, and correspondence ID tracking with saved PDF artifacts.

## Main Modules

- Authentication and role-based access control
- User registration and profile management
- Academic year and evaluation period management
- Questionnaire and category description management
- Faculty subject loading and assignment
- Evaluation forms and submission flow
- Results dashboards for Admin, Staff and Facultye
- Print setup and print result generation
- Correspondence ID storage and PDF file retrieval
- Audit logging
- API endpoints grouped by role under src/Controller/Api

## User Roles

- Admin: full system administration, user and master data management
- Staff: report generation, evaluation operations, correspondence records
- Faculty: subject management, evaluation requests
- Superior: superior evaluation workflow 
- Student: evaluation participation via SET flows and API endpoints

## Frontend Tools Used

- Twig templates for server-rendered UI
- Symfony AssetMapper for frontend asset management
- Symfony UX Turbo for faster page updates
- Symfony Stimulus for client-side controllers
- Vanilla JavaScript for interactive behavior
- CSS for custom styling and print layouts
- Bootstrap Icons in the interface

## Backend Tools Used

- PHP 8.2+
- Symfony 7.4 framework
- Doctrine ORM for data persistence
- Doctrine Migrations for schema versioning
- Symfony Security for authentication and access control
- PostgreSQL as the primary database
- Dompdf for PDF report generation
- PhpSpreadsheet for spreadsheet import/export features
- Tesseract OCR integration via thiagoalessio/tesseract_ocr
- PHPUnit for automated testing

## Project Structure

- src/Controller: web controllers (Admin, Report, Home, Evaluation, etc.)
- src/Controller/Api: role-focused API controllers
- src/Entity: Doctrine entities
- src/Repository: query logic and custom repository methods
- templates: Twig views and print templates
- migrations: database migration files
- public/uploads/correspondence: saved correspondence artifacts (HTML/PDF)

## Notes

- Correspondence records are available under Reports > Correspondence.
- Saved correspondence PDFs are generated from print templates and stored in public/uploads/correspondence.
- API routes are attribute-based and auto-loaded from src/Controller.

## Run With Vercel (Local)

- Install Node.js and Vercel CLI access (`npx` is used automatically).
- Start Vercel dev mode from the project root:

```bash
composer run run:vercel
```

## Deploy To Vercel

- This project now includes [vercel.json](vercel.json) and [api/index.php](api/index.php) for Symfony routing on Vercel.
- Set these environment variables in your Vercel project:
	- APP_ENV=prod
	- APP_DEBUG=0
	- APP_SECRET=<your-secret>
	- DATABASE_URL=<your-mysql-or-postgres-dsn>
	- TRUSTED_PROXIES=*
	- DEFAULT_URI=https://<your-vercel-domain>
	- APP_SHARE_DIR=/tmp/share
- Deploy from the project root:

```bash
composer run deploy:vercel
```

- Note: OCR features that rely on system Tesseract may not be available on Vercel serverless runtime.
