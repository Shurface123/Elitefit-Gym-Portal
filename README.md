# EliteFitGym - Gym Management System

![EliteFitGym Logo](Public/assets/images/logo.png) <!-- Add your logo if available -->

## 📋 Project Overview

EliteFitGym is a state-of-the-art gym management system designed to revolutionize fitness center operations. This all-in-one solution provides a seamless experience for gym owners, trainers, and members, offering powerful tools for scheduling, member management, progress tracking, and facility management. Built with modern web technologies, EliteFitGym ensures reliability, security, and scalability for fitness businesses of all sizes.

## ✨ Features

### 👥 Member Features
- **Dashboard**: Personalized overview of upcoming sessions, progress, and notifications
- **Profile Management**: Update personal information, profile picture, and preferences
- **Session Booking**: Easy booking system for personal training sessions
- **Class Scheduling**: View and enroll in group fitness classes
- **Trainer Directory**: Browse and connect with certified trainers
- **Progress Tracking**: Log and visualize fitness progress with charts and metrics
- **Workout Plans**: Access personalized workout routines and exercise libraries
- **Nutrition Tracking**: Log meals and track nutritional intake
- **Equipment Reservation**: Book gym equipment in advance
- **Session History**: View past sessions and trainer feedback
- **Recurring Appointments**: Set up regular training sessions
- **Rating System**: Rate and review training sessions

### 🏋️ Trainer Features
- **Profile Management**: Professional profile with specialties and certifications
- **Availability Calendar**: Set and manage available time slots
- **Session Management**: View and manage upcoming training sessions
- **Member Progress**: Track and update member progress and goals
- **Communication Tools**: In-app messaging with members
- **Session Notes**: Keep detailed notes for each training session
- **Rating & Reviews**: View and respond to member feedback
- **Recurring Sessions**: Manage repeating training appointments

### 👨‍💼 Admin Features
- **User Management**: Complete control over member and trainer accounts
- **Registration Approval**: Review and approve new member registrations
- **Class Management**: Schedule and manage group fitness classes
- **Equipment Management**: Track and manage gym equipment
- **Reports & Analytics**: Generate detailed reports on membership, attendance, and revenue
- **System Configuration**: Customize system settings and preferences
- **User Archival**: Archive inactive users while preserving data
- **Registration Analytics**: Track and analyze member registration trends
- **Bulk Operations**: Perform batch updates on user accounts
- **Backup & Restore**: System backup and data recovery options

## 🚀 Installation

### Prerequisites
- **Web Server**: Apache 2.4+ or Nginx
- **PHP**: 7.4 or higher with the following extensions:
  - PDO PHP Extension
  - OpenSSL PHP Extension
  - Mbstring PHP Extension
  - Tokenizer PHP Extension
  - JSON PHP Extension
  - cURL PHP Extension
  - Fileinfo PHP Extension
  - GD Library (for image processing)
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Composer**: For PHP dependency management
- **Node.js**: 14.x or higher with NPM
- **SMTP Server**: For email functionality

### System Requirements
- Minimum 2GB RAM (4GB recommended)
- At least 1GB free disk space
- Modern web browser (Chrome, Firefox, Safari, Edge)

### Setup Instructions

1. **Clone the repository**
   ```bash
   git clone https://github.com/Shurface123/Elitefit-Gym-Portal.git
   cd Elitefit-Gym-Portal
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Configure the database**
   - Create a new MySQL database
   - Import the database schema:
     ```bash
     mysql -u username -p database_name < database/elitefitgym.sql
     ```
   - Configure database credentials in `Public/config/database.php`

4. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   Update the following in `.env`:
   ```
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=http://your-domain.com
   
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=elitefitgym
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   
   MAIL_MAILER=smtp
   MAIL_HOST=smtp.mailtrap.io
   MAIL_PORT=2525
   MAIL_USERNAME=null
   MAIL_PASSWORD=null
   MAIL_ENCRYPTION=null
   MAIL_FROM_ADDRESS="hello@example.com"
   MAIL_FROM_NAME="${APP_NAME}"
   ```

5. **Set up storage and permissions**
   ```bash
   php artisan storage:link
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data .  # For Apache
   ```

6. **Install and compile frontend assets**
   ```bash
   npm install
   npm run production
   ```

7. **Run database migrations and seeders**
   ```bash
   php artisan migrate --seed
   ```

8. **Set up cron jobs** (for scheduled tasks)
   ```
   * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
   ```

9. **Configure web server**
   - **Apache**: Ensure mod_rewrite is enabled
   - **Nginx**: Use the provided configuration in `nginx.example.conf`

10. **Verify installation**
    - Visit `http://your-domain.com/install` (if applicable)
    - Or access the site directly to see if it loads correctly

## 📂 Project Structure

```
elitefitgym/
├── Controllers/         # Application controllers
│   ├── Admin/           # Admin panel controllers
│   ├── Member/          # Member area controllers
│   └── Trainer/         # Trainer area controllers
│
├── Model/              # Database models and business logic
│   ├── User.php         # User model
│   ├── Trainer.php      # Trainer model
│   └── ...
│
├── Public/             # Publicly accessible files
│   ├── admin/           # Admin panel
│   │   ├── dashboard.php
│   │   ├── users.php
│   │   └── ...
│   │
│   ├── assets/         # Static assets
│   │   ├── css/        # Stylesheets
│   │   ├── js/         # JavaScript files
│   │   └── images/     # Images and icons
│   │
│   ├── config/        # Configuration files
│   ├── member/         # Member area
│   │   ├── dashboard.php
│   │   ├── profile.php
│   │   └── ...
│   │
│   └── trainer/        # Trainer area
│       ├── dashboard.php
│       ├── schedule.php
│       └── ...
│
├── vendor/             # Composer dependencies
├── .env                # Environment configuration
├── .htaccess          # Apache configuration
├── composer.json      # PHP dependencies
├── package.json       # Frontend dependencies
└── README.md          # This file
```

## 🔄 API Endpoints

### Authentication
- `POST /api/register` - Register a new user
- `POST /api/login` - Authenticate user
- `POST /api/logout` - Log user out
- `POST /api/forgot-password` - Request password reset
- `POST /api/reset-password` - Reset password

### Members
- `GET /api/members` - List all members (admin only)
- `GET /api/members/{id}` - Get member details
- `PUT /api/members/{id}` - Update member profile
- `GET /api/members/{id}/sessions` - Get member's training sessions

### Trainers
- `GET /api/trainers` - List all trainers
- `GET /api/trainers/{id}` - Get trainer details
- `GET /api/trainers/{id}/availability` - Get trainer availability
- `POST /api/trainers/{id}/book` - Book a session with trainer

### Sessions
- `GET /api/sessions` - List all sessions
- `POST /api/sessions` - Create new session (trainer/admin)
- `GET /api/sessions/{id}` - Get session details
- `PUT /api/sessions/{id}` - Update session
- `DELETE /api/sessions/{id}` - Cancel session

## 🔒 Security

### Authentication
- Secure password hashing using bcrypt
- CSRF protection
- Rate limiting on authentication endpoints
- Session management with secure, HTTP-only cookies

### Data Protection
- Prepared statements to prevent SQL injection
- Input validation and sanitization
- XSS protection
- Secure file upload handling

### Best Practices
- Regular security updates
- Principle of least privilege
- Secure headers (CSP, XSS Protection, etc.)
- Regular security audits

## ⚙️ Configuration

### Environment Configuration

#### Database Configuration
Update your `.env` file with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=elitefitgym
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

#### Email Configuration
Configure your email settings for system notifications:
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@yourdomain.com
MAIL_FROM_NAME="${APP_NAME}"
```

#### Application Settings
```env
APP_NAME="EliteFitGym"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-domain.com
APP_TIMEZONE=UTC

# Session and encryption
SESSION_DRIVER=file
SESSION_LIFETIME=120
ENCRYPTION_KEY=your-encryption-key
```

### File Permissions
Set the correct permissions:
```bash
# Storage and cache directories
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/

# If using Apache
chown -R www-data:www-data .

# If you have file uploads
chmod -R 775 public/uploads/
```

### Cron Jobs
Set up the following cron job for scheduled tasks:
```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

### Queue Workers
For better performance, configure a queue worker:
```bash
# Start the queue worker
nohup php artisan queue:work --tries=3 > /dev/null &
```

## 🛠 Development

### Local Development
1. Clone the repository
2. Run `composer install`
3. Copy `.env.example` to `.env` and configure
4. Run `php artisan key:generate`
5. Set up your local database
6. Run migrations: `php artisan migrate --seed`
7. Install frontend dependencies: `npm install`
8. Start the development server: `php artisan serve`
9. Start Vite: `npm run dev`

### Testing
Run the test suite with:
```bash
php artisan test
```

### Code Style
This project follows PSR-12 coding standards. To automatically fix code style issues:
```bash
composer fix-style
```

### Database Migrations
Create a new migration:
```bash
php artisan make:migration create_table_name_table
```

Run migrations:
```bash
php artisan migrate
```

Rollback the last migration:
```bash
php artisan migrate:rollback
```

## 🛠 Development Workflow

### Branching Strategy
We follow the Git Flow branching model:
- `main` - Production code (always deployable)
- `develop` - Integration branch for features
- `feature/` - New features being developed
- `bugfix/` - Bug fixes
- `hotfix/` - Critical production fixes

### Coding Standards
- **PHP**: PSR-12 coding standard
- **JavaScript**: StandardJS
- **CSS**: BEM methodology
- **Database**: Use migrations for all schema changes

### Commit Message Guidelines
Use the following format for commit messages:
```
<type>(<scope>): <subject>

[optional body]

[optional footer]
```

Types:
- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation changes
- `style`: Code style changes
- `refactor`: Code changes that neither fixes a bug nor adds a feature
- `test`: Adding missing tests or correcting existing tests
- `chore`: Changes to the build process or auxiliary tools

### Pull Request Process
1. Fork the repository and create your branch from `develop`
2. Make your changes following the coding standards
3. Add tests for your changes
4. Update the documentation if needed
5. Ensure all tests pass
6. Submit a pull request to the `develop` branch

### Code Review Guidelines
- Keep PRs small and focused on a single feature/fix
- Include clear descriptions of changes
- Reference related issues
- Ensure all tests pass
- Get at least one approval before merging

### Release Process
1. Create a release branch from `develop`
2. Run all tests and fix any issues
3. Update version numbers and changelog
4. Create a PR to merge into `main`
5. Tag the release in GitHub
6. Merge to `main` and deploy to production
7. Merge `main` back into `develop`

## 📄 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🤝 Contributing

We welcome contributions from the community. Here's how you can help:

1. **Report Bugs**: File an issue on GitHub
2. **Suggest Features**: Open an issue with the "enhancement" label
3. **Submit Code**: Follow our contribution guidelines
4. **Improve Documentation**: Help us make the docs better

### Code of Conduct
Please read our [Code of Conduct](CODE_OF_CONDUCT.md) before contributing.

### Development Dependencies
- PHP 7.4+
- Composer
- Node.js 14.x+
- MySQL 5.7+ or MariaDB 10.3+

### Getting Started
1. Fork the repository
2. Clone your fork: `git clone https://github.com/Shurface123/Elitefit-Gym-Portal.git`
3. Install dependencies: `composer install && npm install`
4. Set up your `.env` file
5. Run the development server: `php artisan serve`

## 📧 Support & Contact

For support or questions, please contact:
- **Email**: [lovelacejohnbaidoo@gmail.com](mailto:lovelacejohnbaidoo@gmail.com)
- **Issue Tracker**: [GitHub Issues](https://github.com/Shurface123/Elitefit-Gym-Portal/issues)
- **Documentation**: [Wiki](https://github.com/Shurface123/Elitefit-Gym-Portal/wiki)

## 📚 Additional Resources

- [API Documentation](https://github.com/Shurface123/Elitefit-Gym-Portal/wiki/API-Documentation)
- [User Guide](https://github.com/Shurface123/Elitefit-Gym-Portal/wiki/User-Guide)
- [Developer Guide](https://github.com/Shurface123/Elitefit-Gym-Portal/wiki/Developer-Guide)
- [Changelog](CHANGELOG.md)

## 🙏 Acknowledgments

- Thanks to all contributors who have helped shape this project
- Built with the support of the open source community
- Special thanks to our beta testers and early adopters

---

<div align="center">
  <sub>Built with ❤️ by LOVELACE JOHN KWAKU BAIDOO | &copy; 2025 EliteFitGym. All rights reserved.</sub>
  <br>
  <sub>Made with PHP, MySQL, and JavaScript</sub>
</div>
