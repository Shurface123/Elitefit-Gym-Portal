<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ELITEFIT GYM</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link  href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            /* Light Theme (Default) */
            --primary: #ff6600;
            --primary-light: #ff8533;
            --primary-dark: #e65c00;
            --secondary: #000000;
            --secondary-light: #333333;
            --light: #ffffff;
            --gray: #f5f5f5;
            --dark-gray: #333333;
            --text-color: #333333;
            --bg-color: #ffffff;
            --card-bg: #ffffff;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --header-bg: #000000;
            --header-text: #ffffff;
            --border-color: #eee;
            --footer-bg: #000000;
            --footer-text: #ffffff;
            --transition: all 0.3s ease;
        }

        /* Dark Theme */
        html[data-theme='dark'] {
            --primary: #ff6600;
            --primary-light: #ff8533;
            --primary-dark: #e65c00;
            --secondary: #ffffff;
            --secondary-light: #f5f5f5;
            --light: #1a1a1a;
            --gray: #222222;
            --dark-gray: #cccccc;
            --text-color: #f5f5f5;
            --bg-color: #121212;
            --card-bg: #1e1e1e;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            --header-bg: #1a1a1a;
            --header-text: #ffffff;
            --border-color: #333;
            --footer-bg: #1a1a1a;
            --footer-text: #ffffff;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            overflow-x: hidden;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        /* Typography */
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Poppins', sans-serif;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1rem;
            text-align: center;
            color: var(--text-color);
            transition: color 0.3s ease;
        }
        
        p {
            line-height: 1.6;
            margin-bottom: 1rem;
            text-align: center;
            color: var(--text-color);
            transition: color 0.3s ease;
        }
        
        /* Header Styles */
        header {
            background-color: var(--header-bg);
            color: var(--header-text);
            padding: 15px 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.2);
            transition: var(--transition);
        }
        
        header.scrolled {
            padding: 10px 0;
            background-color: rgba(0, 0, 0, 0.9);
        }

        html[data-theme='dark'] header.scrolled {
            background-color: rgba(26, 26, 26, 0.9);
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            font-size: 32px;
            font-weight: 700;
            color: #FF5E3A;
            text-decoration: none;
            transition: var(--transition);
        }

        .brand-text {
            font-size: 28px;
            font-weight: 700;
            /*background: linear-gradient(to right, #ff9500, #ff5e3a);*/
        }
        
        .logo:hover {
            transform: scale(1.05);
        }
        
        .logo i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 32px;
        }
        
        .nav-links {
            display: flex;
            list-style: none;
        }
        
        .nav-links li {
            margin-left: 30px;
            position: relative;
        }
        
        .nav-links a {
            color: var(--header-text);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            font-size: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 5px 0;
            position: relative;
        }
        
        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: var(--primary);
            transition: var(--transition);
        }
        
        .nav-links a:hover {
            color: var(--primary);
        }
        
        .nav-links a:hover::after {
            width: 100%;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        /* Contact Form Styles */
.contact-form-container {
    background: rgba(255, 255, 255, 0.05);
    padding: 2rem;
    border-radius: 10px;
    margin-top: 2rem;
}

.contact-form .form-group {
    margin-bottom: 1.5rem;
}

.contact-form input,
.contact-form textarea {
    width: 100%;
    padding: 12px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: var(--text-color);
    border-radius: 5px;
    transition: var(--transition);
}

.contact-form input:focus,
.contact-form textarea:focus {
    border-color: var(--primary);
    box-shadow: 0 0 8px rgba(255, 102, 0, 0.3);
}

.contact-form button {
    background: var(--primary);
    color: #fff;
    padding: 12px 30px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    transition: var(--transition);
}

.contact-form button:hover {
    background: var(--primary-dark);
    transform: translateY(-2px);
}

.form-status {
    margin-top: 1rem;
    padding: 1rem;
    border-radius: 5px;
    display: none;
}

        .form-status.success {
            background: rgba(40, 167, 69, 0.15);
    border: 1px solid #28a745;
    color: #28a745;
 }

        .form-status.error {
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid #dc3545;
            color: #dc3545;
        }

        .theme-toggle {
            background: none;
            border: none;
            color: var(--header-text);
            font-size: 20px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
        }

        .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: rotate(30deg);
        }

        html[data-theme='dark'] .theme-toggle .fa-moon {
            display: none;
        }

        html[data-theme='dark'] .theme-toggle .fa-sun {
            display: inline-block;
        }

        html[data-theme='light'] .theme-toggle .fa-sun {
            display: none;
        }

        html[data-theme='light'] .theme-toggle .fa-moon {
            display: inline-block;
        }
        
        .auth-buttons {
            display: flex;
            gap: 15px;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 0;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.1);
            transition: var(--transition);
            z-index: -1;
        }
        
        .btn:hover::before {
            width: 100%;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            box-shadow: 0 4px 15px rgba(255, 102, 0, 0.3);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255, 102, 0, 0.4);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--header-text);
            border: 2px solid var(--primary);
            box-shadow: 0 4px 15px rgba(255, 102, 0, 0.1);
        }
        
        .btn-outline:hover {
            background-color: var(--primary);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(255, 102, 0, 0.3);
        }
        
        .btn-dark {
            background-color: var(--secondary);
            color: #ffffff;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }
        
        .btn-dark:hover {
            background-color: var(--secondary-light);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: var(--header-text);
            font-size: 24px;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .mobile-menu-btn:hover {
            color: var(--primary);
        }
        
        /* Hero Section */
        .hero {
            background: linear-gradient(rgba(0, 0, 0, 0.7), rgba(0, 0, 0, 0.7)), url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            height: 120vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            margin-top: 0;
            position: relative;
            overflow: hidden;
        }
        
        .hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /*background: linear-gradient(45deg, rgba(255, 102, 0, 0.3), rgba(0, 0, 0, 0.7));*/
            z-index: 1;
        }
        
        .hero-content {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            position: relative;
            z-index: 2;
            max-width: 9500px;
            padding: 0 20px;
        }
        
        .hero h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 60px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 25px;
            line-height: 1.1;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            color: #ffffff;
        }
        
        .hero p {
            font-family: 'Montserrat', sans-serif;
            font-size: 22px;
            font-weight: 400;
            line-height: 1.6;
            margin-bottom: 35px;
            max-width: 800px;
            color: #ffffff;
        }
        
        .hero-actions {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .hero-controls {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 10px;
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .fitness-metrics {
            display: flex;
            justify-content: center;
            gap: 30px;
            margin-top: 40px;
        }

        .metric {
            display: flex;
            flex-direction: column;
            align-items: center;
            color: #ffffff;
        }

        .metric-value {
            font-size: 36px;
            font-weight: 700;
            color: var(--primary);
        }

        .metric-label {
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .scroll-down {
            position: absolute;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            color: #ffffff;
            font-size: 30px;
            animation: bounce 2s infinite;
            cursor: pointer;
            z-index: 2;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {
                transform: translateY(0) translateX(-50%);
            }
            40% {
                transform: translateY(-20px) translateX(-50%);
            }
            60% {
                transform: translateY(-10px) translateX(-50%);
            }
        }
        
        /* Features Section */
        .features {
            padding: 120px 0;
            background-color: var(--bg-color);
            position: relative;
            overflow: hidden;
        }
        
        .features::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100px;
            background: linear-gradient(to bottom, var(--gray), transparent);
            opacity: 0.7;
        }

        .features-bg-shape {
            position: absolute;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(255, 102, 0, 0.05), transparent);
            border-radius: 50%;
            top: -200px;
            right: -200px;
            z-index: 0;
        }
        
        .section-title {
            text-align: center;
            margin-bottom: 60px;
            position: relative;
        }
        
        .section-title h2 {
            font-size: 40px;
            margin-bottom: 20px;
            position: relative;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        
        .section-title h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background-color: var(--primary);
        }
        
        .section-title p {
            font-size: 18px;
            color: var(--dark-gray);
            max-width: 700px;
            margin: 0 auto;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            position: relative;
            z-index: 1;
        }
        
        .feature-card {
            background-color: var(--card-bg);
            border-radius: 15px;
            padding: 40px 30px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            text-align: center;
            position: relative;
            overflow: hidden;
            z-index: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border: 1px solid transparent;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 0;
            background: linear-gradient(to bottom, rgba(255, 102, 0, 0.05), transparent);
            transition: var(--transition);
            z-index: -1;
        }
        
        .feature-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 15px 40px rgba(255, 102, 0, 0.2);
            border-color: rgba(255, 102, 0, 0.1);
        }
        
        .feature-card:hover::before {
            height: 100%;
        }
        
        .feature-icon {
            font-size: 50px;
            color: var(--primary);
            margin-bottom: 25px;
            transition: var(--transition);
            background: linear-gradient(45deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .feature-card:hover .feature-icon {
            transform: scale(1.1);
        }
        
        .feature-card h3 {
            font-size: 24px;
            margin-bottom: 15px;
            transition: var(--transition);
        }
        
        .feature-card p {
            color: var(--dark-gray);
            line-height: 1.7;
            margin-bottom: 20px;
        }
        
        .feature-link {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            transition: var(--transition);
            margin-top: auto;
            cursor: pointer;
        }
        
        .feature-link i {
            margin-left: 5px;
            transition: var(--transition);
        }
        
        .feature-link:hover {
            color: var(--primary-dark);
        }
        
        .feature-link:hover i {
            transform: translateX(5px);
        }

        /* Feature Modal */
        .feature-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
            backdrop-filter: blur(5px);
        }

        .feature-modal.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background-color: var(--card-bg);
            border-radius: 15px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 40px;
            position: relative;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            transform: translateY(50px);
            opacity: 0;
            transition: transform 0.5s ease, opacity 0.5s ease;
        }

        .feature-modal.active .modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            color: var(--text-color);
            font-size: 24px;
            cursor: pointer;
            transition: var(--transition);
        }

        .modal-close:hover {
            color: var(--primary);
            transform: rotate(90deg);
        }

        .modal-header {
            margin-bottom: 30px;
            text-align: center;
            position: relative;
        }

        .modal-header h3 {
            font-size: 28px;
            margin-bottom: 10px;
            color: var(--text-color);
        }

        .modal-header::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            background-color: var(--primary);
        }

        .modal-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            align-items: center;
        }

        .modal-image {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .modal-image img {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.5s ease;
        }

        .modal-image:hover img {
            transform: scale(1.05);
        }

        .modal-text p {
            text-align: left;
            margin-bottom: 20px;
            line-height: 1.7;
        }

        .modal-features {
            list-style: none;
            margin-top: 20px;
            text-align: left;
        }

        .modal-features li {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
        }

        .modal-features li i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 16px;
        }

        .modal-cta {
            margin-top: 30px;
            text-align: center;
        }

        @media (max-width: 768px) {
            .modal-body {
                grid-template-columns: 1fr;
            }
        }
        
        /* About Section */
        .about {
            padding: 120px 0;
            background-color: var(--gray);
            position: relative;
            overflow: hidden;
        }
        
        .about::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 300px;
            /*background: radial-gradient(circle, rgba(255, 102, 0, 0.1), transparent);?*/
            border-radius: 50%;
        }
        
        .about::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 200px;
            height: 200px;
            /*background: radial-gradient(circle, rgba(255, 102, 0, 0.1), transparent);*/
            border-radius: 50%;
        }
        
        .about-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        
        .about-image {
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
            position: relative;
        }
        
        .about-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /*background: linear-gradient(45deg, rgba(255, 102, 0, 0.3), transparent);*/
            z-index: 1;
        }
        
        .about-image img {
            width: 100%;
            height: auto;
            display: block;
            transition: var(--transition);
        }
        
        .about-image:hover img {
            transform: scale(1.05);
        }
        
        .about-text {
            text-align: left;
        }
        
        .about-text h2 {
            font-size: 36px;
            margin-bottom: 20px;
            position: relative;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .about-text h2::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 80px;
            height: 4px;
            background-color: var(--primary);
        }
        
        .about-text p {
            margin-bottom: 20px;
            line-height: 1.7;
            color: var(--dark-gray);
            text-align: left;
        }
        
        .about-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 40px;
        }
        
        .stat {
            text-align: center;
            background-color: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }
        
        .stat:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(255, 102, 0, 0.2);
        }
        
        .stat-number {
            font-size: 40px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 5px;
            background: linear-gradient(45deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-text {
            font-size: 16px;
            color: var(--dark-gray);
            font-weight: 600;
        }

        /* Workout Categories Section */
        .workout-categories {
            padding: 120px 0;
            background-color: var(--bg-color);
            position: relative;
            overflow: hidden;
        }

        .workout-categories::before {
            content: '';
            position: absolute;
            bottom: -100px;
            left: -100px;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255, 102, 0, 0.05), transparent);
            border-radius: 50%;
            z-index: 0;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            position: relative;
            z-index: 1;
        }

        .category-card {
            position: relative;
            border-radius: 15px;
            overflow: hidden;
            height: 300px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }

        .category-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(255, 102, 0, 0.2);
        }

        .category-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }

        .category-card:hover .category-image {
            transform: scale(1.1);
        }

        .category-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 30px;
            transition: var(--transition);
        }

        .category-card:hover .category-overlay {
            background: linear-gradient(to top, rgba(255, 102, 0, 0.8), rgba(0, 0, 0, 0.5));
        }

        .category-title {
            color: #ffffff;
            font-size: 24px;
            margin-bottom: 10px;
            text-align: left;
        }

        .category-description {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            margin-bottom: 15px;
            text-align: left;
            max-height: 0;
            overflow: hidden;
            opacity: 0;
            transition: max-height 0.5s ease, opacity 0.5s ease, margin-bottom 0.5s ease;
        }

        .category-card:hover .category-description {
            max-height: 100px;
            opacity: 1;
            margin-bottom: 15px;
        }

        .category-link {
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            transition: var(--transition);
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .category-link i {
            margin-left: 5px;
            transition: var(--transition);
        }

        .category-link:hover {
            color: var(--primary-light);
        }

        .category-link:hover i {
            transform: translateX(5px);
        }
        
        /* Programs Section */
        .programs {
            padding: 120px 0;
            background-color: var(--gray);
            position: relative;
            overflow: hidden;
        }
        
        .programs::before {
            content: '';
            position: absolute;
            top: 50%;
            right: -100px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255, 102, 0, 0.1), transparent);
            border-radius: 50%;
            z-index: 0;
        }
        
        .programs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            position: relative;
            z-index: 1;
        }
        
        .program-card {
            background-color: var(--card-bg);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            height: 100%;
            display: flex;
            flex-direction: column;
            border: 1px solid transparent;
        }
        
        .program-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 15px 40px rgba(255, 102, 0, 0.2);
            border-color: rgba(255, 102, 0, 0.1);
        }
        
        .program-image {
            height: 250px;
            overflow: hidden;
            position: relative;
        }
        
        .program-image::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, transparent, rgba(0, 0, 0, 0.7));
            z-index: 1;
            opacity: 0;
            transition: var(--transition);
        }
        
        .program-card:hover .program-image::before {
            opacity: 1;
        }
        
        .program-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s;
        }
        
        .program-card:hover .program-image img {
            transform: scale(1.1);
        }
        
        .program-content {
            padding: 30px;
            text-align: center;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .program-content h3 {
            font-size: 24px;
            margin-bottom: 15px;
            position: relative;
            display: inline-block;
        }
        
        .program-content h3::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background-color: var(--primary);
        }
        
        .program-content p {
            color: var(--dark-gray);
            margin-bottom: 20px;
            line-height: 1.7;
        }
        
        .program-price {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 20px;
            background: linear-gradient(45deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .program-features {
            list-style: none;
            margin-bottom: 25px;
            text-align: left;
        }
        
        .program-features li {
            padding: 8px 0;
            border-bottom: 1px dashed var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .program-features li:last-child {
            border-bottom: none;
        }
        
        .program-features i {
            color: var(--primary);
            margin-right: 10px;
        }
        
        .program-card .btn {
            margin-top: auto;
        }
        
        /* Testimonials Section */
        .testimonials {
            padding: 120px 0;
            background-color: var(--bg-color);
            position: relative;
            overflow: hidden;
        }
        
        .testimonials::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('https://images.unsplash.com/photo-1534438327276-14e5300c3a48?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80') center/cover no-repeat fixed;
            opacity: 0.05;
            z-index: 0;
        }
        
        .testimonials-container {
            position: relative;
            z-index: 1;
        }
        
        .testimonials-slider {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            overflow: hidden;
        }
        
        .testimonials-track {
            display: flex;
            transition: transform 0.5s ease;
        }
        
        .testimonial-item {
            flex: 0 0 100%;
            background-color: var(--card-bg);
            border-radius: 20px;
            padding: 40px;
            box-shadow: var(--card-shadow);
            margin: 20px;
            text-align: center;
            position: relative;
            transition: var(--transition);
            border: 1px solid transparent;
        }
        
        .testimonial-item::before {
            content: '\f10d';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            top: 20px;
            left: 20px;
            font-size: 30px;
            color: rgba(255, 102, 0, 0.1);
        }
        
        .testimonial-item::after {
            content: '\f10e';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            bottom: 20px;
            right: 20px;
            font-size: 30px;
            color: rgba(255, 102, 0, 0.1);
        }
        
        .testimonial-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 25px;
            border: 5px solid var(--primary);
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
            position: relative;
        }
        
        .testimonial-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }
        
        .testimonial-item:hover .testimonial-image img {
            transform: scale(1.1);
        }
        
        .testimonial-text {
            font-style: italic;
            margin-bottom: 25px;
            line-height: 1.7;
            color: var(--dark-gray);
            font-size: 18px;
        }
        
        .testimonial-author {
            font-weight: 700;
            color: var(--text-color);
            font-size: 20px;
            margin-bottom: 5px;
        }
        
        .testimonial-role {
            color: var(--primary);
            font-size: 16px;
            font-weight: 600;
        }
        
        .testimonial-controls {
            display: flex;
            justify-content: center;
            margin-top: 40px;
            gap: 20px;
        }
        
        .testimonial-btn {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--card-bg);
            color: var(--primary);
            border: 2px solid var(--primary);
            font-size: 18px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .testimonial-btn:hover {
            background-color: var(--primary);
            color: #ffffff;
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
        }
        
        .testimonial-dots {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            gap: 10px;
        }
        
        .dot {
            width: 12px;
            height: 12px;
            background-color: #ccc;
            border-radius: 50%;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .dot.active {
            background-color: var(--primary);
            transform: scale(1.2);
        }
        
        /* Pricing Section */
        .pricing {
            padding: 120px 0;
            background-color: var(--gray);
            position: relative;
            overflow: hidden;
        }
        
        .pricing::before {
            content: '';
            position: absolute;
            bottom: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255, 102, 0, 0.1), transparent);
            border-radius: 50%;
        }
        
        .pricing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            position: relative;
            z-index: 1;
        }
        
        .pricing-card {
            background-color: var(--card-bg);
            border-radius: 20px;
            padding: 50px 30px;
            box-shadow: var(--card-shadow);
            text-align: center;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 1px solid transparent;
        }
        
        .pricing-card.popular {
            transform: scale(1.05);
            z-index: 2;
            box-shadow: 0 15px 40px rgba(255, 102, 0, 0.2);
            border-color: rgba(255, 102, 0, 0.1);
        }
        
        .pricing-card.popular::before {
            content: 'Most Popular';
            position: absolute;
            top: 20px;
            right: -35px;
            background-color: var(--primary);
            color: #ffffff;
            padding: 8px 40px;
            transform: rotate(45deg);
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        
        .pricing-card:hover {
            transform: translateY(-15px);
            box-shadow: 0 15px 40px rgba(255, 102, 0, 0.3);
            border-color: rgba(255, 102, 0, 0.1);
        }
        
        .pricing-header {
            margin-bottom: 30px;
            position: relative;
        }
        
        .pricing-name {
            font-size: 28px;
            margin-bottom: 15px;
            position: relative;
            display: inline-block;
        }
        
        .pricing-name::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 50px;
            height: 3px;
            background-color: var(--primary);
        }
        
        .pricing-price {
            font-size: 56px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 10px;
            line-height: 1;
            background: linear-gradient(45deg, var(--primary), var(--primary-dark));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .pricing-duration {
            color: var(--dark-gray);
            font-size: 16px;
            font-weight: 500;
        }
        
        .pricing-features {
            list-style: none;
            margin-bottom: 30px;
            text-align: left;
        }
        
        .pricing-features li {
            padding: 12px 0;
            border-bottom: 1px dashed var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .pricing-features li:last-child {
            border-bottom: none;
        }
        
        .pricing-features i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 16px;
        }
        
        .pricing-features .unavailable {
            color: #aaa;
            text-decoration: line-through;
        }
        
        .pricing-features .unavailable i {
            color: #ccc;
        }
        
        /* CTA Section */
        .cta {
            padding: 120px 0;
            background: linear-gradient(rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.8)), url('https://images.unsplash.com/photo-1517836357463-d25dfeac3438?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: #ffffff;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .cta::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /*background: linear-gradient(45deg, rgba(255, 102, 0, 0.3), transparent);*/
            z-index: 1;
        }
        
        .cta-content {
            position: relative;
            z-index: 2;
        }
        
        .cta h2 {
            font-size: 48px;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 2px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            color: #ffffff;
        }
        
        .cta p {
            font-size: 20px;
            margin-bottom: 30px;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
            line-height: 1.7;
            color: #ffffff;
        }
        
        .cta-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
        }
        
        /* Footer */
        footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 80px 0 20px;
            position: relative;
            overflow: hidden;
        }
        
        footer::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255, 102, 0, 0.1), transparent);
            border-radius: 50%;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 40px;
            margin-bottom: 60px;
            position: relative;
            z-index: 1;
        }
        
        .footer-logo {
            display: flex;
            align-items: center;
            font-size: 28px;
            font-weight: 800;
            color: var(--footer-text);
            text-decoration: none;
            margin-bottom: 20px;
        }
        
        .footer-logo i {
            color: var(--primary);
            margin-right: 10px;
            font-size: 32px;
        }
        
        .footer-about p {
            line-height: 1.7;
            margin-bottom: 25px;
            text-align: left;
            color: var(--footer-text);
        }
        
        .social-links {
            display: flex;
            gap: 15px;
        }
        
        .social-links a {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: var(--dark-gray);
            color: var(--light);
            border-radius: 50%;
            transition: var(--transition);
            font-size: 18px;
        }
        
        .social-links a:hover {
            background-color: var(--primary);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
        }
        
        .footer-links h3, .footer-contact h3, .footer-newsletter h3 {
            font-size: 22px;
            margin-bottom: 25px;
            position: relative;
            text-align: left;
            color: var(--footer-text);
        }
        
        .footer-links h3::after, .footer-contact h3::after, .footer-newsletter h3::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 50px;
            height: 3px;
            background-color: var(--primary);
        }
        
        .footer-links ul {
            list-style: none;
            text-align: left;
        }
        
        .footer-links li {
            margin-bottom: 15px;
        }
        
        .footer-links a {
            color: #ccc;
            text-decoration: none;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
        }
        
        .footer-links a i {
            margin-right: 8px;
            color: var(--primary);
            font-size: 14px;
        }
        
        .footer-links a:hover {
            color: var(--primary);
            transform: translateX(5px);
        }
        
        .contact-info {
            margin-bottom: 25px;
            text-align: left;
        }
        
        .contact-info p {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            text-align: left;
            color: var(--footer-text);
        }
        
        .contact-info i {
            color: var(--primary);
            margin-right: 15px;
            font-size: 20px;
            width: 20px;
        }
        
        .newsletter-form {
            display: flex;
            margin-top: 20px;
        }
        
        .newsletter-input {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 50px 0 0 50px;
            font-size: 14px;
            outline: none;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--footer-text);
        }
        
        .newsletter-btn {
            background-color: var(--primary);
            color: #ffffff;
            border: none;
            padding: 0 20px;
            border-radius: 0 50px 50px 0;
            cursor: pointer;
            transition: var(--transition);
            font-size: 18px;
        }
        
        .newsletter-btn:hover {
            background-color: var(--primary-dark);
        }
        
        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid #444;
            position: relative;
            z-index: 1;
        }
        
        .footer-bottom p {
            font-size: 14px;
            color: var(--footer-text);
        }
        
        .footer-bottom a {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .footer-bottom a:hover {
            color: var(--primary-light);
        }
        
        /* Responsive Styles */
        @media (max-width: 1200px) {
            .hero h1 {
                font-size: 48px;
            }
            
            .section-title h2 {
                font-size: 36px;
            }
            
            .about-content {
                gap: 30px;
            }
        }
        
        @media (max-width: 992px) {
            .nav-links {
                display: none;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .hero h1 {
                font-size: 40px;
            }
            
            .hero p {
                font-size: 18px;
            }
            
            .about-content {
                grid-template-columns: 1fr;
            }
            
            .about-image {
                order: -1;
            }
            
            .about-text h2, .about-text p {
                text-align: center;
            }
            
            .about-text h2::after {
                left: 50%;
                transform: translateX(-50%);
            }
            
            .pricing-card.popular {
                transform: scale(1);
            }

            .fitness-metrics {
                flex-wrap: wrap;
            }
        }
        
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 40px;
            }
            
            .hero p {
                font-size: 18px;
            }
            
            .hero-buttons {
                flex-direction: column;
                gap: 15px;
            }
            
            .section-title h2 {
                font-size: 30px;
            }
            
            .about-stats {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .testimonial-item {
                padding: 30px 20px;
            }
            
            .cta h2 {
                font-size: 36px;
            }
            
            .cta p {
                font-size: 16px;
            }
            
            .cta-buttons {
                flex-direction: column;
                gap: 15px;
            }
            
            .modal-body {
                grid-template-columns: 1fr;
            }
            
            .trainer-specialties {
                flex-direction: column;
                align-items: center;
            }
        }
        
        @media (max-width: 576px) {
            .header-right {
                gap: 10px;
            }
            
            .auth-buttons {
                display: none;
            }
            
            .hero h1 {
                font-size: 30px;
            }
            
            .feature-card, .program-card, .pricing-card {
                padding: 30px 20px;
            }
            
            .testimonial-image {
                width: 100px;
                height: 100px;
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .footer-logo {
                justify-content: center;
            }
            
            .social-links {
                justify-content: center;
            }
            
            .footer-links h3, .footer-contact h3, .footer-newsletter h3,
            .footer-links h3::after, .footer-contact h3::after, .footer-newsletter h3::after,
            .footer-links ul, .contact-info, .contact-info p {
                text-align: center;
            }
            
            .footer-links h3::after, .footer-contact h3::after, .footer-newsletter h3::after {
                left: 50%;
                transform: translateX(-50%);
            }
            
            .contact-info p {
                justify-content: center;
            }
            
            .hero h1 {
                font-size: 32px;
            }
            
            .hero-actions {
                width: 100%;
            }
            
            .hero-buttons {
                width: 100%;
                flex-direction: column;
            }
            
            .hero-controls {
                width: 100%;
            }
            
            .auth-buttons {
                width: 100%;
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
            }
        }
        
        /* Mobile Menu */
        .mobile-menu {
            position: fixed;
            top: 80px;
            left: 0;
            width: 100%;
            background-color: rgba(0, 0, 0, 0.95);
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            display: none;
            z-index: 999;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transform: translateY(-20px);
            opacity: 0;
            transition: all 0.3s ease;
        }

        html[data-theme='dark'] .mobile-menu {
            background-color: rgba(26, 26, 26, 0.95);
        }

        .mobile-menu.active {
            display: block;
            transform: translateY(0);
            opacity: 1;
        }

        .mobile-menu ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .mobile-menu li {
            margin-bottom: 20px;
        }

        .mobile-menu a {
            color: #ffffff;
            text-decoration: none;
            font-size: 18px;
            display: block;
            padding: 10px 0;
            border-bottom: 1px solid #444;
            transition: var(--transition);
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .mobile-menu a:hover {
            color: var(--primary);
            border-bottom-color: var(--primary);
            transform: translateX(10px);
        }

        .mobile-controls {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
            margin-top: 30px;
        }

        .mobile-theme-toggle {
            margin-bottom: 15px;
        }

        .mobile-auth {
            display: flex;
            flex-direction: column;
            gap: 15px;
            width: 100%;
            max-width: 300px;
            margin: 0 auto;
        }
        
        /* Animation */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .animate {
            opacity: 0;
            animation: fadeIn 0.5s ease forwards;
        }
        
        .delay-1 {
            animation-delay: 0.1s;
        }
        
        .delay-2 {
            animation-delay: 0.2s;
        }
        
        .delay-3 {
            animation-delay: 0.3s;
        }
        
        .delay-4 {
            animation-delay: 0.4s;
        }
        
        .delay-5 {
            animation-delay: 0.5s;
        }
        
        /* Scroll to top button */
        .scroll-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            background-color: var(--primary);
            color: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(255, 102, 0, 0.3);
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
            z-index: 999;
        }
        
        .scroll-top.active {
            opacity: 1;
            visibility: visible;
        }
        
        .scroll-top:hover {
            background-color: var(--primary-dark);
            transform: translateY(-5px);
        }

    /* Trainers Section */
    .trainers {
        padding: 120px 0;
        background-color: var(--bg-color);
        position: relative;
        overflow: hidden;
    }
    
    .trainers::before {
        content: '';
        position: absolute;
        top: -100px;
        right: -100px;
        width: 400px;
        height: 400px;
        background: radial-gradient(circle, rgba(255, 102, 0, 0.05), transparent);
        border-radius: 50%;
        z-index: 0;
    }
    
    .trainers-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 30px;
        position: relative;
        z-index: 1;
    }
    
    .trainer-card {
        background-color: var(--card-bg);
        border-radius: 15px;
        overflow: hidden;
        box-shadow: var(--card-shadow);
        transition: var(--transition);
        height: 100%;
        display: flex;
        flex-direction: column;
        border: 1px solid transparent;
    }
    
    .trainer-card:hover {
        transform: translateY(-10px);
        box-shadow: 0 15px 40px rgba(255, 102, 0, 0.2);
        border-color: rgba(255, 102, 0, 0.1);
    }
    
    .trainer-image {
        height: 300px;
        overflow: hidden;
        position: relative;
    }
    
    .trainer-image img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.5s;
    }
    
    .trainer-card:hover .trainer-image img {
        transform: scale(1.1);
    }
    
    .trainer-content {
        padding: 25px;
        text-align: center;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }
    
    .trainer-name {
        font-size: 24px;
        margin-bottom: 5px;
        color: var(--text-color);
    }
    
    .trainer-role {
        color: var(--primary);
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 15px;
    }
    
    .trainer-description {
        color: var(--dark-gray);
        margin-bottom: 20px;
        line-height: 1.6;
        text-align: center;
    }
    
    .trainer-specialties {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 10px;
        margin-bottom: 20px;
    }
    
    .trainer-specialties span {
        background-color: rgba(255, 102, 0, 0.1);
        color: var(--primary);
        padding: 5px 12px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .trainer-social {
        display: flex;
        justify-content: center;
        gap: 15px;
        margin-top: auto;
    }
    
    .trainer-social a {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        background-color: var(--gray);
        color: var(--dark-gray);
        border-radius: 50%;
        transition: var(--transition);
        font-size: 16px;
    }
    
    .trainer-social a:hover {
        background-color: var(--primary);
        color: #ffffff;
        transform: translateY(-3px);
    }
    
    @media (max-width: 768px) {
        .hero h1 {
            font-size: 40px;
        }
        
        .hero p {
            font-size: 18px;
        }
        
        .hero-controls {
            flex-direction: column;
            gap: 15px;
        }
    }
    </style>

    <!-- EmailJS Integration -->
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/@emailjs/browser@3/dist/email.min.js"></script>
<script>
    emailjs.init('cnQDc1l7rR1AVU1oQ');
</script>
</head>
<body>
    <!-- Header -->
    <header id="header">
        <div class="container header-container">
            <a href="#" class="logo">
                <i class="fas fa-dumbbell"></i>
                ELITEFIT
            </a>
            
            <ul class="nav-links">
                <li><a href="#home">HOME</a></li>
                <li><a href="#about">ABOUT</a></li>
                <li><a href="#programs">PROGRAMS</a></li>
                <li><a href="#pricing">PRICING</a></li>
                <li><a href="#testimonials">TESTIMONIALS</a></li>
                <li><a href="#contact">CONTACT</a></li>
            </ul>
            
            <button class="mobile-menu-btn">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </header>
    
    <!-- Mobile Menu -->
    <div class="mobile-menu">
        <ul>
            <li><a href="#home">HOME</a></li>
            <li><a href="#about">ABOUT</a></li>
            <li><a href="#programs">PROGRAMS</a></li>
            <li><a href="#pricing">PRICING</a></li>
            <li><a href="#testimonials">TESTIMONIALS</a></li>
            <li><a href="#contact">CONTACT</a></li>
        </ul>
        
        <div class="mobile-controls">
            <button class="theme-toggle mobile-theme-toggle" aria-label="Toggle theme">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>
            
            <div class="mobile-auth">
                <a href="login.php" class="btn btn-outline">SIGN IN</a>
                <a href="register.php" class="btn btn-primary">SIGN UP</a>
            </div>
        </div>
    </div>
    
    <!-- Hero Section -->
    <section class="hero" id="home">
        <div class="container">
            <div class="hero-content animate">
                <h1>Transform Your <span>Body</span>, Transform Your <span>Life</span></h1>
                <p>Join EliteFit Gym today and experience state-of-the-art equipment, expert trainers, and a supportive community dedicated to helping you achieve your fitness goals.</p>
                
                <div class="hero-actions">
                    <div class="hero-buttons">
                        <a href="register.php" class="btn btn-primary">Start Your Journey</a>
                        <a href="#programs" class="btn btn-outline">Explore Programs</a>
                    </div>
                    
                    <div class="hero-controls">
                        <button class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">
                            <i class="fas fa-moon"></i>
                            <i class="fas fa-sun"></i>
                        </button>
                        
                        <div class="auth-buttons">
                            <a href="login.php" class="btn btn-outline btn-sm">SIGN IN</a>
                            <a href="register.php" class="btn btn-primary btn-sm">SIGN UP</a>
                        </div>
                    </div>
                </div>
                
                <div class="fitness-metrics">
                    <div class="metric">
                        <div class="metric-value">500+</div>
                        <div class="metric-label">Daily Workouts</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value">15k+</div>
                        <div class="metric-label">Active Members</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value">98%</div>
                        <div class="metric-label">Success Rate</div>
                    </div>
                </div>
            </div>
        </div>
        <a href="#features" class="scroll-down">
            <i class="fas fa-chevron-down"></i>
        </a>
    </section>
    
    <!-- Features Section -->
    <section class="features" id="features">
        <div class="features-bg-shape"></div>
        <div class="container">
            <div class="section-title animate">
                <h2>Why Choose EliteFit?</h2>
                <p>We offer more than just a gym - we provide a complete fitness experience tailored to your needs</p>
            </div>
            
            <div class="features-grid">
                <div class="feature-card animate delay-1">
                    <div class="feature-icon">
                        <i class="fas fa-dumbbell"></i>
                    </div>
                    <h3>State-of-the-Art Equipment</h3>
                    <p>Access to the latest fitness technology and premium equipment to maximize your workout efficiency and results.</p>
                    <a class="feature-link" data-feature="equipment">Learn More <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <div class="feature-card animate delay-2">
                    <div class="feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <h3>Expert Trainers</h3>
                    <p>Our certified personal trainers create customized workout plans tailored to your specific goals and fitness level.</p>
                    <a class="feature-link" data-feature="trainers">Learn More <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <div class="feature-card animate delay-3">
                    <div class="feature-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <h3>Diverse Classes</h3>
                    <p>From high-intensity interval training to yoga, we offer a wide range of classes to keep your routine fresh and exciting.</p>
                    <a class="feature-link" data-feature="classes">Learn More <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <div class="feature-card animate delay-4">
                    <div class="feature-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <h3>24/7 Access</h3>
                    <p>Work out on your schedule with round-the-clock access to our facilities, perfect for early birds and night owls alike.</p>
                    <a class="feature-link" data-feature="access">Learn More <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <div class="feature-card animate delay-5">
                    <div class="feature-icon">
                        <i class="fas fa-heart"></i>
                    </div>
                    <h3>Supportive Community</h3>
                    <p>Join a community of like-minded individuals who will motivate and inspire you throughout your fitness journey.</p>
                    <a class="feature-link" data-feature="community">Learn More <i class="fas fa-arrow-right"></i></a>
                </div>
                
                <div class="feature-card animate delay-5">
                    <div class="feature-icon">
                        <i class="fas fa-mobile-alt"></i>
                    </div>
                    <h3>Custom Gym Planning Portal</h3>
                    <p>Create personalized workout plans, track your progress, and connect with trainers through our innovative digital platform.</p>
                    <a class="feature-link" data-feature="portal">Learn More <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>

    <!-- Feature Modals -->
    <div class="feature-modal" id="equipment-modal">
        <div class="modal-content">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <div class="modal-header">
                <h3>State-of-the-Art Equipment</h3>
            </div>
            <div class="modal-body">
                <div class="modal-image">
                    <img src="https://images.unsplash.com/photo-1540497077202-7c8a3999166f?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Gym Equipment">
                </div>
                <div class="modal-text">
                    <p>At EliteFit, we pride ourselves on offering the most advanced fitness equipment available. Our facilities are equipped with top-of-the-line machines from leading manufacturers, ensuring you have access to the best tools for your fitness journey.</p>
                    <p>Our equipment is regularly maintained and updated to provide you with a safe and effective workout experience. Whether you're focusing on strength training, cardio, or functional fitness, we have the equipment you need to reach your goals.</p>
                    <ul class="modal-features">
                        <li><i class="fas fa-check"></i> Latest cardio machines with integrated entertainment systems</li>
                        <li><i class="fas fa-check"></i> Comprehensive free weight area with Olympic lifting platforms</li>
                        <li><i class="fas fa-check"></i> Specialized equipment for functional training and HIIT workouts</li>
                        <li><i class="fas fa-check"></i> Recovery zone with foam rollers, stretching equipment, and more</li>
                        <li><i class="fas fa-check"></i> Smart fitness technology that tracks your performance</li>
                    </ul>
                    <div class="modal-cta">
                        <a href="register.php" class="btn btn-primary">Join Now</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="feature-modal" id="trainers-modal">
        <div class="modal-content">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <div class="modal-header">
                <h3>Expert Trainers</h3>
            </div>
            <div class="modal-body">
                <div class="modal-image">
                    <img src="https://images.unsplash.com/photo-1571019614242-c5c5dee9f50b?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Personal Trainer">
                </div>
                <div class="modal-text">
                    <p>Our team of certified personal trainers are passionate about helping you achieve your fitness goals. With diverse backgrounds in exercise science, nutrition, and specialized training methodologies, our trainers bring expertise and enthusiasm to every session.</p>
                    <p>Each trainer undergoes rigorous certification and continuous education to stay at the forefront of fitness innovation. When you work with an EliteFit trainer, you're getting personalized guidance from a true fitness professional.</p>
                    <ul class="modal-features">
                        <li><i class="fas fa-check"></i> Nationally certified personal trainers</li>
                        <li><i class="fas fa-check"></i> Specialized expertise in weight loss, muscle building, and athletic performance</li>
                        <li><i class="fas fa-check"></i> Customized workout programs tailored to your goals</li>
                        <li><i class="fas fa-check"></i> Nutritional guidance and meal planning support</li>
                        <li><i class="fas fa-check"></i> Regular progress assessments and program adjustments</li>
                    </ul>
                    <div class="modal-cta">
                        <a href="register.php" class="btn btn-primary">Meet Our Trainers</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="feature-modal" id="classes-modal">
        <div class="modal-content">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <div class="modal-header">
                <h3>Diverse Classes</h3>
            </div>
            <div class="modal-body">
                <div class="modal-image">
                    <img src="https://images.unsplash.com/photo-1571945153237-4929e783af4a?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Group Fitness Class">
                </div>
                <div class="modal-text">
                    <p>Variety is key to a sustainable fitness routine. That's why EliteFit offers over 50 different group fitness classes each week, catering to all fitness levels and interests. Our energetic instructors create an atmosphere that's both challenging and supportive.</p>
                    <p>From high-intensity cardio to mind-body practices, our diverse class schedule ensures you'll never get bored with your workout routine. Try something new or stick with your favorites – the choice is yours!</p>
                    <ul class="modal-features">
                        <li><i class="fas fa-check"></i> HIIT and circuit training classes for maximum calorie burn</li>
                        <li><i class="fas fa-check"></i> Yoga and Pilates for flexibility, balance, and core strength</li>
                        <li><i class="fas fa-check"></i> Cycling and cardio dance for heart-pumping workouts</li>
                        <li><i class="fas fa-check"></i> Strength-focused classes for muscle building and toning</li>
                        <li><i class="fas fa-check"></i> Specialized classes for seniors, prenatal fitness, and rehabilitation</li>
                    </ul>
                    <div class="modal-cta">
                        <a href="register.php" class="btn btn-primary">View Class Schedule</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="feature-modal" id="access-modal">
        <div class="modal-content">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <div class="modal-header">
                <h3>24/7 Access</h3>
            </div>
            <div class="modal-body">
                <div class="modal-image">
                    <img src="https://images.unsplash.com/photo-1558611848-73f7eb4001a1?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1471&q=80" alt="24/7 Gym Access">
                </div>
                <div class="modal-text">
                    <p>We understand that life doesn't always follow a 9-to-5 schedule. That's why EliteFit offers 24/7 access to our facilities, allowing you to work out whenever it fits your lifestyle – whether that's early morning, late night, or anywhere in between.</p>
                    <p>Our secure access system ensures that members can safely enter the facility at any time, while our comprehensive security measures provide peace of mind during off-peak hours.</p>
                    <ul class="modal-features">
                        <li><i class="fas fa-check"></i> Secure keycard or app-based entry system</li>
                        <li><i class="fas fa-check"></i> Full access to all equipment and facilities around the clock</li>
                        <li><i class="fas fa-check"></i> Well-lit parking areas and interior spaces</li>
                        <li><i class="fas fa-check"></i> Emergency assistance buttons throughout the facility</li>
                        <li><i class="fas fa-check"></i> Surveillance systems for your safety and security</li>
                    </ul>
                    <div class="modal-cta">
                        <a href="register.php" class="btn btn-primary">Get 24/7 Access</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="feature-modal" id="community-modal">
        <div class="modal-content">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <div class="modal-header">
                <h3>Supportive Community</h3>
            </div>
            <div class="modal-body">
                <div class="modal-image">
                    <img src="https://images.unsplash.com/photo-1517931524326-bdd55a541177?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Fitness Community">
                </div>
                <div class="modal-text">
                    <p>At EliteFit, we believe that fitness is better together. Our supportive community of members and staff creates an environment where everyone feels welcome, motivated, and inspired to achieve their personal best.</p>
                    <p>From workout buddies to friendly competitions, our community aspect helps keep you accountable and makes fitness fun. Many of our members have formed lasting friendships that extend beyond the gym walls.</p>
                    <ul class="modal-features">
                        <li><i class="fas fa-check"></i> Regular community events and fitness challenges</li>
                        <li><i class="fas fa-check"></i> Member spotlights and success stories</li>
                        <li><i class="fas fa-check"></i> Buddy system for workout partners</li>
                        <li><i class="fas fa-check"></i> Online community forums and social media groups</li>
                        <li><i class="fas fa-check"></i> Charitable fitness events that give back to our local community</li>
                    </ul>
                    <div class="modal-cta">
                        <a href="register.php" class="btn btn-primary">Join Our Community</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="feature-modal" id="portal-modal">
        <div class="modal-content">
            <button class="modal-close"><i class="fas fa-times"></i></button>
            <div class="modal-header">
                <h3>Custom Gym Planning Portal</h3>
            </div>
            <div class="modal-body">
                <div class="modal-image">
                    <img src="https://images.unsplash.com/photo-1550345332-09e3ac987658?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Fitness App">
                </div>
                <div class="modal-text">
                    <p>Our innovative Custom Gym Planning Portal brings your fitness journey into the digital age. This comprehensive platform allows you to create, track, and optimize your workouts while staying connected with trainers and fellow members.</p>
                    <p>Available on both web and mobile devices, the portal serves as your personal fitness hub, providing valuable insights into your progress and helping you stay on track to reach your goals.</p>
                    <ul class="modal-features">
                        <li><i class="fas fa-check"></i> Customizable workout plans with video demonstrations</li>
                        <li><i class="fas fa-check"></i> Progress tracking with visual charts and metrics</li>
                        <li><i class="fas fa-check"></i> Direct messaging with trainers for guidance and support</li>
                        <li><i class="fas fa-check"></i> Nutrition tracking and meal planning tools</li>
                        <li><i class="fas fa-check"></i> Class scheduling and reservation system</li>
                        <li><i class="fas fa-check"></i> Integration with fitness wearables and smart devices</li>
                    </ul>
                    <div class="modal-cta">
                        <a href="register.php" class="btn btn-primary">Try Our Portal</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Workout Categories Section -->
    <section class="workout-categories">
        <div class="container">
            <div class="section-title animate">
                <h2>Workout Categories</h2>
                <p>Explore our diverse range of workout options to find what works best for your fitness goals</p>
            </div>
            
            <div class="categories-grid">
                <div class="category-card animate delay-1">
                    <img src="https://images.unsplash.com/photo-1581009146145-b5ef050c2e1e?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Strength Training" class="category-image">
                    <div class="category-overlay">
                        <h3 class="category-title">Strength Training</h3>
                        <p class="category-description">Build muscle, increase strength, and improve your overall physique with our comprehensive strength training programs.</p>
                        <a href="#programs" class="category-link">Explore Programs <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                
                <div class="category-card animate delay-2">
                    <img src="https://images.unsplash.com/photo-1518611012118-696072aa579a?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Cardio Fitness" class="category-image">
                    <div class="category-overlay">
                        <h3 class="category-title">Cardio Fitness</h3>
                        <p class="category-description">Improve your cardiovascular health, burn calories, and boost your endurance with our dynamic cardio programs.</p>
                        <a href="#programs" class="category-link">Explore Programs <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                
                <div class="category-card animate delay-3">
                    <img src="https://images.unsplash.com/photo-1599901860904-17e6ed7083a0?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Yoga & Flexibility" class="category-image">
                    <div class="category-overlay">
                        <h3 class="category-title">Yoga & Flexibility</h3>
                        <p class="category-description">Enhance your flexibility, balance, and mental well-being with our yoga and stretching classes led by expert instructors.</p>
                        <a href="#programs" class="category-link">Explore Programs <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                
                <div class="category-card animate delay-4">
                    <img src="https://images.unsplash.com/photo-1434682881908-b43d0467b798?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1474&q=80" alt="HIIT & Circuit" class="category-image">
                    <div class="category-overlay">
                        <h3 class="category-title">HIIT & Circuit</h3>
                        <p class="category-description">Maximize calorie burn and improve conditioning with high-intensity interval training and circuit workouts.</p>
                        <a href="#programs" class="category-link">Explore Programs <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                
                <div class="category-card animate delay-5">
                    <img src="https://images.unsplash.com/photo-1534438327276-14e5300c3a48?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80" alt="Functional Training" class="category-image">
                    <div class="category-overlay">
                        <h3 class="category-title">Functional Training</h3>
                        <p class="category-description">Improve everyday movements, balance, and coordination with exercises that mimic real-life activities and movements.</p>
                        <a href="#programs" class="category-link">Explore Programs <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
                
                <div class="category-card animate delay-6">
                    <img src="https://images.unsplash.com/photo-1574680178050-55c6a6a96e0a?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1469&q=80" alt="Group Training" class="category-image">
                    <div class="category-overlay">
                        <h3 class="category-title">Group Training</h3>
                        <p class="category-description">Experience the motivation and energy of working out with others in our diverse range of group fitness classes.</p>
                        <a href="#programs" class="category-link">Explore Programs <i class="fas fa-arrow-right"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- About Section -->
    <section class="about" id="about">
        <div class="container">
            <div class="about-content">
                <div class="about-text animate">
                    <h2>About EliteFit Gym</h2>
                    <p>EliteFit Gym was founded in 2010 with a simple mission: to create a fitness environment where everyone feels welcome and empowered to achieve their personal best. What started as a small local gym has grown into a premier fitness destination with multiple locations across the country.</p>
                    <p>Our philosophy is centered around the belief that fitness is not just about physical transformation, but also about mental well-being and building a sustainable, healthy lifestyle. We've carefully designed our facilities and programs to support members at every stage of their fitness journey.</p>
                    <p>At EliteFit, we're more than just a gym - we're a community dedicated to helping you become the best version of yourself. Our team of passionate fitness professionals is committed to providing personalized guidance, motivation, and support to help you reach your goals.</p>
                    
                    <div class="about-stats">
                        <div class="stat">
                            <div class="stat-number">15+</div>
                            <div class="stat-text">Locations</div>
                        </div>
                        
                        <div class="stat">
                            <div class="stat-number">50+</div>
                            <div class="stat-text">Expert Trainers</div>
                        </div>
                        
                        <div class="stat">
                            <div class="stat-number">10k+</div>
                            <div class="stat-text">Happy Members</div>
                        </div>
                    </div>
                </div>
                
                <div class="about-image animate delay-2">
                    <img src="https://images.unsplash.com/photo-1571902943202-507ec2618e8f?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="EliteFit Gym Interior">
                </div>
            </div>
        </div>
    </section>

    <!-- Trainers Section -->
    <section class="trainers" id="trainers">
        <div class="container">
            <div class="section-title animate">
                <h2>Meet Our Expert Trainers</h2>
                <p>Our certified fitness professionals are dedicated to helping you achieve your goals</p>
            </div>
            
            <div class="trainers-grid">
                <div class="trainer-card animate delay-1">
                    <div class="trainer-image">
                        <img src="https://images.unsplash.com/photo-1571019613454-1cb2f99b2d8b?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Alex Johnson">
                    </div>
                    <div class="trainer-content">
                        <h3 class="trainer-name">Alex Johnson</h3>
                        <p class="trainer-role">Strength & Conditioning Specialist</p>
                        <p class="trainer-description">With over 10 years of experience in strength training, Alex specializes in powerlifting techniques and muscle building programs for all fitness levels.</p>
                        <div class="trainer-specialties">
                            <span>Powerlifting</span>
                            <span>Muscle Building</span>
                            <span>Sports Performance</span>
                        </div>
                        <div class="trainer-social">
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-twitter"></i></a>
                            <a href="#"><i class="fab fa-linkedin-in"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="trainer-card animate delay-2">
                    <div class="trainer-image">
                        <img src="https://images.unsplash.com/photo-1594381898411-846e7d193883?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1374&q=80" alt="Sarah Martinez">
                    </div>
                    <div class="trainer-content">
                        <h3 class="trainer-name">Sarah Martinez</h3>
                        <p class="trainer-role">Yoga & Flexibility Coach</p>
                        <p class="trainer-description">Sarah is a certified yoga instructor with expertise in various yoga styles. She focuses on improving flexibility, balance, and mental wellness through mindful practice.</p>
                        <div class="trainer-specialties">
                            <span>Vinyasa Yoga</span>
                            <span>Flexibility</span>
                            <span>Meditation</span>
                        </div>
                        <div class="trainer-social">
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-twitter"></i></a>
                            <a href="#"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="trainer-card animate delay-3">
                    <div class="trainer-image">
                        <img src="https://images.unsplash.com/photo-1567013127542-490d757e51fc?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1374&q=80" alt="Marcus Williams">
                    </div>
                    <div class="trainer-content">
                        <h3 class="trainer-name">Marcus Williams</h3>
                        <p class="trainer-role">HIIT & Functional Training Expert</p>
                        <p class="trainer-description">Marcus specializes in high-intensity interval training and functional fitness. His energetic approach helps clients maximize calorie burn and improve overall conditioning.</p>
                        <div class="trainer-specialties">
                            <span>HIIT</span>
                            <span>Functional Training</span>
                            <span>Weight Loss</span>
                        </div>
                        <div class="trainer-social">
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-facebook-f"></i></a>
                            <a href="#"><i class="fab fa-tiktok"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="trainer-card animate delay-4">
                    <div class="trainer-image">
                        <img src="https://images.unsplash.com/photo-1548690312-e3b507d8c110?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1374&q=80" alt="Emily Chen">
                    </div>
                    <div class="trainer-content">
                        <h3 class="trainer-name">Emily Chen</h3>
                        <p class="trainer-role">Nutrition & Wellness Coach</p>
                        <p class="trainer-description">Emily combines fitness training with nutrition expertise to create holistic wellness plans. She specializes in helping clients achieve sustainable lifestyle changes.</p>
                        <div class="trainer-specialties">
                            <span>Nutrition Planning</span>
                            <span>Weight Management</span>
                            <span>Holistic Wellness</span>
                        </div>
                        <div class="trainer-social">
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-pinterest"></i></a>
                            <a href="#"><i class="fab fa-youtube"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="trainer-card animate delay-5">
                    <div class="trainer-image">
                        <img src="https://images.unsplash.com/photo-1534367610401-9f5ed68180aa?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="David Rodriguez">
                    </div>
                    <div class="trainer-content">
                        <h3 class="trainer-name">David Rodriguez</h3>
                        <p class="trainer-role">Cardio & Endurance Specialist</p>
                        <p class="trainer-description">David focuses on improving cardiovascular health and endurance. His programs are ideal for runners, cyclists, and those looking to improve stamina.</p>
                        <div class="trainer-specialties">
                            <span>Endurance Training</span>
                            <span>Running Programs</span>
                            <span>Cardiovascular Health</span>
                        </div>
                        <div class="trainer-social">
                            <a href="#"><i class="fab fa-instagram"></i></a>
                            <a href="#"><i class="fab fa-strava"></i></a>
                            <a href="#"><i class="fab fa-twitter"></i></a>
                        </div>
                    </div>
                </div>
                
                <div class="trainer-card animate delay-6">
                    <div class="trainer-image">
                        <img src="https://images.unsplash.com/photo-1609899537878-88d5ba429bdb?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1374&q=80" alt="Olivia Thompson">
                    </div>
                    <div class="trainer-content">
                        <h3 class="trainer-name">Olivia Thompson</h3>
                        <p class="trainer-role">Senior Fitness & Rehabilitation</p>
                        <p class="trainer-description">Olivia specializes in fitness programs for seniors and rehabilitation. Her gentle approach focuses on improving mobility, balance, and strength for all ages.</p>
                        <div class="trainer-specialties">
                            <span>Senior Fitness</span>
                            <span>Rehabilitation</span>
                            <span>Mobility Training</span>
                        </div>
                        <div class="trainer-social">
                            <a href="#"><i class="fab fa-facebook-f"></i></a>
                            <a href="#"><i class="fab fa-linkedin-in"></i></a>
                            <a href="#"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Programs Section -->
    <section class="programs" id="programs">
        <div class="container">
            <div class="section-title animate">
                <h2>Our Fitness Programs</h2>
                <p>Discover a variety of programs designed to help you achieve your fitness goals</p>
            </div>
            
            <div class="programs-grid">
                <div class="program-card animate delay-1">
                    <div class="program-image">
                        <img src="https://images.unsplash.com/photo-1517838277536-f5f99be501cd?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Strength Training">
                    </div>
                    <div class="program-content">
                        <h3>Strength Training</h3>
                        <p>Build muscle, increase strength, and improve your overall physique with our comprehensive strength training program.</p>
                        <div class="program-price">$49.99/month</div>
                        <ul class="program-features">
                            <li><i class="fas fa-check"></i> Personalized workout plans</li>
                            <li><i class="fas fa-check"></i> Access to all strength equipment</li>
                            <li><i class="fas fa-check"></i> Weekly progress tracking</li>
                            <li><i class="fas fa-check"></i> Nutrition guidance</li>
                        </ul>
                        <a href="register.php" class="btn btn-primary">Join Now</a>
                    </div>
                </div>
                
                <div class="program-card animate delay-2">
                    <div class="program-image">
                        <img src="https://images.unsplash.com/photo-1518611012118-696072aa579a?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Cardio Fitness">
                    </div>
                    <div class="program-content">
                        <h3>Cardio Fitness</h3>
                        <p>Improve your cardiovascular health, burn calories, and boost your endurance with our dynamic cardio program.</p>
                        <div class="program-price">$39.99/month</div>
                        <ul class="program-features">
                            <li><i class="fas fa-check"></i> Variety of cardio equipment</li>
                            <li><i class="fas fa-check"></i> HIIT training sessions</li>
                            <li><i class="fas fa-check"></i> Heart rate monitoring</li>
                            <li><i class="fas fa-check"></i> Endurance building</li>
                        </ul>
                        <a href="register.php" class="btn btn-primary">Join Now</a>
                    </div>
                </div>
                
                <div class="program-card animate delay-3">
                    <div class="program-image">
                        <img src="https://images.unsplash.com/photo-1599901860904-17e6ed7083a0?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Yoga & Flexibility">
                    </div>
                    <div class="program-content">
                        <h3>Yoga & Flexibility</h3>
                        <p>Enhance your flexibility, balance, and mental well-being with our yoga and stretching classes led by expert instructors.</p>
                        <div class="program-price">$44.99/month</div>
                        <ul class="program-features">
                            <li><i class="fas fa-check"></i> Daily yoga classes</li>
                            <li><i class="fas fa-check"></i> Meditation sessions</li>
                            <li><i class="fas fa-check"></i> Flexibility training</li>
                            <li><i class="fas fa-check"></i> Stress reduction techniques</li>
                        </ul>
                        <a href="register.php" class="btn btn-primary">Join Now</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Testimonials Section -->
    <section class="testimonials" id="testimonials">
        <div class="container testimonials-container">
            <div class="section-title animate">
                <h2>Success Stories</h2>
                <p>Hear what our members have to say about their experience at EliteFit Gym</p>
            </div>
            
            <div class="testimonials-slider animate delay-1">
                <div class="testimonials-track">
                    <div class="testimonial-item">
                        <div class="testimonial-image">
                            <img src="https://images.unsplash.com/photo-1500648767791-00dcc994a43e?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=687&q=80" alt="John Smith">
                        </div>
                        <p class="testimonial-text">"Joining EliteFit was the best decision I've made for my health. The trainers are knowledgeable and supportive, and the community keeps me motivated. I've lost 30 pounds and gained confidence I never thought possible!"</p>
                        <h4 class="testimonial-author">John Smith</h4>
                        <p class="testimonial-role">Member since 2020</p>
                    </div>
                    
                    <div class="testimonial-item">
                        <div class="testimonial-image">
                            <img src="https://images.unsplash.com/photo-1494790108377-be9c29b29330?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=687&q=80" alt="Sarah Johnson">
                        </div>
                        <p class="testimonial-text">"As a busy professional, I needed a gym that could accommodate my schedule. EliteFit's 24/7 access and variety of classes have made it easy to stay consistent with my workouts. I'm stronger and more energetic than ever!"</p>
                        <h4 class="testimonial-author">Sarah Johnson</h4>
                        <p class="testimonial-role">Member since 2021</p>
                    </div>
                    
                    <div class="testimonial-item">
                        <div class="testimonial-image">
                            <img src="https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=687&q=80" alt="Michael Rodriguez">
                        </div>
                        <p class="testimonial-text">"The Custom Gym Planning Portal has revolutionized my fitness journey. Being able to track my progress and communicate with my trainer has kept me accountable and helped me achieve results I never thought possible."</p>
                        <h4 class="testimonial-author">Michael Rodriguez</h4>
                        <p class="testimonial-role">Member since 2019</p>
                    </div>
                    
                    <div class="testimonial-item">
                        <div class="testimonial-image">
                            <img src="https://images.unsplash.com/photo-1438761681033-6461ffad8d80?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="Emily Chen">
                        </div>
                        <p class="testimonial-text">"After trying several gyms, I finally found my fitness home at EliteFit. The equipment is top-notch, the facilities are always clean, and the staff genuinely cares about helping members succeed. I've recommended EliteFit to all my friends!"</p>
                        <h4 class="testimonial-author">Emily Chen</h4>
                        <p class="testimonial-role">Member since 2022</p>
                    </div>
                    
                    <div class="testimonial-item">
                        <div class="testimonial-image">
                            <img src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=1470&q=80" alt="David Wilson">
                        </div>
                        <p class="testimonial-text">"As a former athlete, I needed a gym that could challenge me. EliteFit's advanced equipment and specialized programs have helped me maintain my fitness level and even set new personal records. The community here is unmatched!"</p>
                        <h4 class="testimonial-author">David Wilson</h4>
                        <p class="testimonial-role">Member since 2018</p>
                    </div>
                </div>
                
                <div class="testimonial-controls">
                    <button class="testimonial-btn prev-btn">
                        <i class="fas fa-chevron-left"></i>
                    </button>
                    <button class="testimonial-btn next-btn">
                        <i class="fas fa-chevron-right"></i>
                    </button>
                </div>
                
                <div class="testimonial-dots">
                    <span class="dot active" data-slide="0"></span>
                    <span class="dot" data-slide="1"></span>
                    <span class="dot" data-slide="2"></span>
                    <span class="dot" data-slide="3"></span>
                    <span class="dot" data-slide="4"></span>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Pricing Section -->
    <section class="pricing" id="pricing">
        <div class="container">
            <div class="section-title animate">
                <h2>Membership Plans</h2>
                <p>Choose the plan that fits your fitness goals and budget</p>
            </div>
            
            <div class="pricing-grid">
                <div class="pricing-card animate delay-1">
                    <div class="pricing-header">
                        <h3 class="pricing-name">Basic</h3>
                        <div class="pricing-price">$29.99</div>
                        <div class="pricing-duration">per month</div>
                    </div>
                    
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Access to gym facilities</li>
                        <li><i class="fas fa-check"></i> Basic equipment usage</li>
                        <li><i class="fas fa-check"></i> 2 group classes per week</li>
                        <li><i class="fas fa-check"></i> Locker room access</li>
                        <li><i class="fas fa-check"></i> Online workout tracking</li>
                        <li class="unavailable"><i class="fas fa-times"></i> Personal training sessions</li>
                        <li class="unavailable"><i class="fas fa-times"></i> Custom Gym Planning Portal</li>
                    </ul>
                    
                    <a href="register.php" class="btn btn-primary">Get Started</a>
                </div>
                
                <div class="pricing-card popular animate delay-2">
                    <div class="pricing-header">
                        <h3 class="pricing-name">Premium</h3>
                        <div class="pricing-price">$59.99</div>
                        <div class="pricing-duration">per month</div>
                    </div>
                    
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> Unlimited gym access</li>
                        <li><i class="fas fa-check"></i> Full equipment usage</li>
                        <li><i class="fas fa-check"></i> Unlimited group classes</li>
                        <li><i class="fas fa-check"></i> Locker room access</li>
                        <li><i class="fas fa-check"></i> Advanced workout tracking</li>
                        <li><i class="fas fa-check"></i> 2 personal training sessions/month</li>
                        <li><i class="fas fa-check"></i> Basic Custom Gym Planning Portal</li>
                        <li><i class="fas fa-check"></i> Nutrition consultation</li>
                    </ul>
                    
                    <a href="register.php" class="btn btn-primary">Get Started</a>
                </div>
                
                <div class="pricing-card animate delay-3">
                    <div class="pricing-header">
                        <h3 class="pricing-name">Elite</h3>
                        <div class="pricing-price">$99.99</div>
                        <div class="pricing-duration">per month</div>
                    </div>
                    
                    <ul class="pricing-features">
                        <li><i class="fas fa-check"></i> 24/7 gym access</li>
                        <li><i class="fas fa-check"></i> Premium equipment priority</li>
                        <li><i class="fas fa-check"></i> Unlimited group classes</li>
                        <li><i class="fas fa-check"></i> Premium locker with amenities</li>
                        <li><i class="fas fa-check"></i> Full Custom Gym Planning Portal</li>
                        <li><i class="fas fa-check"></i> 4 personal training sessions/month</li>
                        <li><i class="fas fa-check"></i> Monthly fitness assessment</li>
                        <li><i class="fas fa-check"></i> Personalized nutrition plan</li>
                        <li><i class="fas fa-check"></i> Priority booking for all services</li>
                    </ul>
                    
                    <a href="register.php" class="btn btn-primary">Get Started</a>
                </div>
            </div>
        </div>
    </section>
    
    <!-- CTA Section -->
    <section class="cta">
        <div class="container cta-content">
            <h2 class="animate">Ready to Transform Your Life?</h2>
            <p class="animate delay-1">Join EliteFit Gym today and take the first step towards a healthier, stronger you. Our expert trainers and supportive community are here to help you achieve your fitness goals.</p>
            <div class="cta-buttons animate delay-2">
                <a href="register.php" class="btn btn-primary">Sign Up Now</a>
                <a href="#contact" class="btn btn-outline">Contact Us</a>
            </div>
        </div>
    </section>
    
    <!-- Footer -->
    <footer id="contact">
        <div class="container">
            <div class="footer-content">
                <div class="footer-about">
                    <a href="#" class="footer-logo">
                        <i class="fas fa-dumbbell"></i>
                        ELITEFIT
                    </a>
                    <p>EliteFit Gym is dedicated to helping you achieve your fitness goals with state-of-the-art equipment, expert trainers, and a supportive community.</p>
                    <div class="social-links">
                        <a href="https://www.facebook.com/ELITEFIT"><i class="fab fa-facebook-f"></i></a>
                        <a href="https://twitter.com/@ELITEFIT"><i class="fab fa-twitter"></i></a>
                        <a href="https://www.instagram.com"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-linkedin-in"></i></a>
                        <a href="https://www.youtube.com/@elitefit_"><i class="fab fa-youtube"></i></a>
                    </div>
                </div>
                
                <div class="footer-links">
                    <h3>QUICK LINKS</h3>
                    <ul>
                        <li><a href="#home"><i class="fas fa-chevron-right"></i> HOME</a></li>
                        <li><a href="#about"><i class="fas fa-chevron-right"></i> ABOUT</a></li>
                        <li><a href="#programs"><i class="fas fa-chevron-right"></i> PROGRAMS</a></li>
                        <li><a href="#pricing"><i class="fas fa-chevron-right"></i> PRICING</a></li>
                        <li><a href="#testimonials"><i class="fas fa-chevron-right"></i> TESTIMONIALS</a></li>
                        <li><a href="#contact"><i class="fas fa-chevron-right"></i> CONTACT</a></li>
                    </ul>
                </div>
                
                <div class="footer-contact">
                    <h3>CONTACT US</h3>
                    <div class="contact-form-container">
    <form id="contactForm" class="contact-form">
        <div class="form-group">
            <input type="text" name="from_name" placeholder="Your Name" required>
        </div>
        <div class="form-group">
            <input type="email" name="from_email" placeholder="Your Email" required>
        </div>
        <div class="form-group">
            <textarea name="message" rows="4" placeholder="Your Message" required></textarea>
        </div>
        <button type="submit">Send Message <i class="fas fa-paper-plane"></i></button>
    </form>
    <div id="formStatus" class="form-status"></div>
</div>
                </div>
                
                <div class="footer-newsletter">
                    <h3>NEWSLETTER</h3>
                    <p>Subscribe to our newsletter for fitness tips, special offers, and updates.</p>
                    <form class="newsletter-form">
                        <input type="email" class="newsletter-input" placeholder="Your email address">
                        <button type="submit" class="newsletter-btn"><i class="fas fa-paper-plane"></i></button>
                    </form>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> ELITEFIT GYM. ALL RIGHTS RESERVED. | DESIGNED WITH <i class="fas fa-heart" style="color: var(--primary);"></i> by <a href="#">LOVELACE JOHN KWAKU BAIDOO</a></p>
            </div>
        </div>
    </footer>
    
    <!-- Scroll to top button -->
    <div class="scroll-top">
        <i class="fas fa-arrow-up"></i>
    </div>
    
    <script>
        // Theme Toggle
        const themeToggle = document.getElementById('theme-toggle');
        const htmlElement = document.documentElement;
        
        // Check for saved theme preference or use default
        const savedTheme = localStorage.getItem('theme') || 'light';
        htmlElement.setAttribute('data-theme', savedTheme);
        
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            htmlElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });
        
        // Mobile Menu Toggle
        const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
        const mobileMenu = document.querySelector('.mobile-menu');
        
        mobileMenuBtn.addEventListener('click', () => {
            mobileMenu.classList.toggle('active');
            if (mobileMenu.classList.contains('active')) {
                mobileMenuBtn.innerHTML = '<i class="fas fa-times"></i>';
            } else {
                mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
            }
        });
        
        // Header Scroll Effect
        const header = document.getElementById('header');
        
        window.addEventListener('scroll', () => {
            if (window.scrollY > 100) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });
        
        // Feature Modal Functionality
        const featureLinks = document.querySelectorAll('.feature-link');
        const featureModals = document.querySelectorAll('.feature-modal');
        const modalCloseButtons = document.querySelectorAll('.modal-close');
        
        featureLinks.forEach(link => {
            link.addEventListener('click', () => {
                const featureType = link.getAttribute('data-feature');
                const modal = document.getElementById(`${featureType}-modal`);
                
                if (modal) {
                    modal.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            });
        });
        
        modalCloseButtons.forEach(button => {
            button.addEventListener('click', () => {
                const modal = button.closest('.feature-modal');
                modal.classList.remove('active');
                document.body.style.overflow = 'auto';
            });
        });
        
        featureModals.forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                    document.body.style.overflow = 'auto';
                }
            });
        });
        
        // Testimonial Slider
        const track = document.querySelector('.testimonials-track');
        const slides = document.querySelectorAll('.testimonial-item');
        const dots = document.querySelectorAll('.dot');
        const prevBtn = document.querySelector('.prev-btn');
        const nextBtn = document.querySelector('.next-btn');
        let currentSlide = 0;
        const slideWidth = slides[0].getBoundingClientRect().width;
        
        // Set initial position
        function setSlidePosition() {
            track.style.transform = `translateX(-${currentSlide * slideWidth}px)`;
            
            // Update active dot
            dots.forEach(dot => dot.classList.remove('active'));
            dots[currentSlide].classList.add('active');
        }
        
        // Next slide
        function nextSlide() {
            currentSlide = (currentSlide + 1) % slides.length;
            setSlidePosition();
        }
        
        // Previous slide
        function prevSlide() {
            currentSlide = (currentSlide - 1 + slides.length) % slides.length;
            setSlidePosition();
        }
        
        // Event listeners
        nextBtn.addEventListener('click', nextSlide);
        prevBtn.addEventListener('click', prevSlide);
        
        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                currentSlide = index;
                setSlidePosition();
            });
        });
        
        // Auto slide every 5 seconds
        setInterval(nextSlide, 5000);
        
        // Animate elements when they come into view
        const animateElements = document.querySelectorAll('.animate');
        
        function checkIfInView() {
            animateElements.forEach(element => {
                const elementTop = element.getBoundingClientRect().top;
                const elementVisible = 150;
                
                if (elementTop < window.innerHeight - elementVisible) {
                    element.style.opacity = '1';
                    element.style.transform = 'translateY(0)';
                }
            });
        }
        
        // Set initial state for animated elements
        animateElements.forEach(element => {
            element.style.opacity = '0';
            element.style.transform = 'translateY(20px)';
            element.style.transition = 'all 0.5s ease';
        });
        
        // Scroll to top button
        const scrollTopBtn = document.querySelector('.scroll-top');
        
        window.addEventListener('scroll', () => {
            if (window.scrollY > 500) {
                scrollTopBtn.classList.add('active');
            } else {
                scrollTopBtn.classList.remove('active');
            }
        });
        
        scrollTopBtn.addEventListener('click', () => {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetId = this.getAttribute('href');
                if (targetId === '#') return;
                
                const targetElement = document.querySelector(targetId);
                if (targetElement) {
                    const headerHeight = document.querySelector('header').offsetHeight;
                    const targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - headerHeight;
                    
                    window.scrollTo({
                        top: targetPosition,
                        behavior: 'smooth'
                    });
                    
                    // Close mobile menu if open
                    if (mobileMenu.classList.contains('active')) {
                        mobileMenu.classList.remove('active');
                        mobileMenuBtn.innerHTML = '<i class="fas fa-bars"></i>';
                    }
                }
            });
        });
        
        // Check if elements are in view on scroll and on page load
        window.addEventListener('scroll', checkIfInView);
        window.addEventListener('load', checkIfInView);
        
        // Initialize testimonials
        setSlidePosition();

        // Mobile Theme Toggle
        const mobileThemeToggle = document.querySelector('.mobile-theme-toggle');
        
        mobileThemeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            
            htmlElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        });

// Add at the top with other initializations
emailjs.init('cnQDc1l7rR1AVU1oQ');

document.getElementById('contactForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const form = this;
    const btn = form.querySelector('button');
    const statusDiv = document.getElementById('formStatus');
    
    // Clear previous state
    statusDiv.style.display = 'none';
    ['from_name', 'from_email', 'message'].forEach(field => 
        form[field].style.borderColor = ''
    );

    // Validate form
    const emptyFields = Array.from(form.elements).filter(el => 
        el.required && !el.value.trim()
    );
    if (emptyFields.length) {
        emptyFields.forEach(field => field.style.borderColor = '#ff4444');
        showFormStatus('Please fill all required fields', 'error');
        return;
    }

    // Prevent double submission
    if (btn.disabled) return;
    btn.disabled = true;
    const originalBtnText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
        // Send to business team - original notification email
        // This sends notification to your business email address configured in EmailJS
        const businessPromise = emailjs.sendForm(
            'service_j5py48p', 
            'template_l6prbrk', // Business notification template
            form
        );

        // Prepare user confirmation email parameters
        // This will be sent to the user who filled out the form
        const userParams = {
            to_email: form.from_email.value,  // The email address entered by the user in the form
            to_name: form.from_name.value,
            message_content: form.message.value.substring(0, 100) + (form.message.value.length > 100 ? '...' : ''),
            gym_name: 'Elitefit Gym',
            confirmation_time: new Date().toLocaleString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }),
            response_time: "24 hours"
        };

        // Send confirmation to user - same service but different template
        const userPromise = emailjs.send(
            'service_j5py48p', // Same service ID as business email
            'template_0gnhwl4', // User confirmation template
            userParams
        );

        // Wait for both emails
        const [businessRes, userRes] = await Promise.all([businessPromise, userPromise]);
        
        if (businessRes.status === 200 && userRes.status === 200) {
            showFormStatus('Message sent! Check your email for confirmation.', 'success');
            form.reset();
        } else {
            throw new Error('Partial delivery - check email status');
        }
    } catch (error) {
        console.error('Dual Email Error:', error);
        const errorMsg = error.text?.includes('quota') 
            ? 'Daily email limit reached' 
            : error.message?.includes('Partial')
            ? 'Message received - confirmation failed'
            : 'Failed to send message';
            
        showFormStatus(errorMsg, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalBtnText;
    }
});

// Enhanced status display
function showFormStatus(message, type) {
    const statusDiv = document.getElementById('formStatus');
    statusDiv.textContent = message;
    statusDiv.className = `form-status ${type}`;
    statusDiv.style.display = 'block';
    
    clearTimeout(statusDiv.timeout);
    statusDiv.timeout = setTimeout(() => {
        statusDiv.style.display = 'none';
    }, 5000);
}
    </script>
</body>
</html>

