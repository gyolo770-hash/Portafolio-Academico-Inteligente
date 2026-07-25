CREATE DATABASE IF NOT EXISTS portafolio_academico
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_spanish_ci;

USE portafolio_academico;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS portfolio_visits;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS moderation_flags;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS system_settings;
DROP TABLE IF EXISTS contact_messages;
DROP TABLE IF EXISTS saved_candidates;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS reminders;
DROP TABLE IF EXISTS recommendations;
DROP TABLE IF EXISTS project_screenshots;
DROP TABLE IF EXISTS resumes;
DROP TABLE IF EXISTS project_skills;
DROP TABLE IF EXISTS certification_skills;
DROP TABLE IF EXISTS user_skills;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS skill_categories;
DROP TABLE IF EXISTS certifications;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS education;
DROP TABLE IF EXISTS experiences;
DROP TABLE IF EXISTS portfolio_settings;
DROP TABLE IF EXISTS user_profiles;
DROP TABLE IF EXISTS social_accounts;
DROP TABLE IF EXISTS email_verifications;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS recruiters;
DROP TABLE IF EXISTS universities;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(60) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE universities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    city VARCHAR(120) NULL,
    state VARCHAR(120) NULL,
    country VARCHAR(120) DEFAULT 'México',
    website VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY universities_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE recruiters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(180) NOT NULL,
    contact_name VARCHAR(160) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    website VARCHAR(255) NULL,
    status ENUM('pendiente', 'activo', 'suspendido') DEFAULT 'pendiente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX recruiters_company_index (company_name),
    INDEX recruiters_status_index (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id INT UNSIGNED NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    avatar_path VARCHAR(255) NULL,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    status ENUM('activo', 'pendiente', 'suspendido') DEFAULT 'pendiente',
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT users_role_id_fk FOREIGN KEY (role_id) REFERENCES roles(id),
    INDEX users_role_id_index (role_id),
    INDEX users_username_index (username),
    INDEX users_email_index (email),
    INDEX users_status_index (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT password_resets_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX password_resets_user_id_index (user_id),
    INDEX password_resets_expires_at_index (expires_at),
    INDEX password_resets_token_index (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE email_verifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT email_verifications_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX email_verifications_user_id_index (user_id),
    INDEX email_verifications_token_index (token_hash),
    INDEX email_verifications_expires_at_index (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE social_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider ENUM('google', 'facebook', 'github') NOT NULL,
    provider_user_id VARCHAR(190) NOT NULL,
    provider_email VARCHAR(180) NULL,
    access_token TEXT NULL,
    refresh_token TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT social_accounts_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX social_accounts_user_id_index (user_id),
    INDEX social_accounts_provider_index (provider),
    UNIQUE KEY social_provider_user_unique (provider, provider_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE user_profiles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    university_id INT UNSIGNED NULL,
    about_me TEXT NULL,
    career VARCHAR(160) NULL,
    graduation_year YEAR NULL,
    phone VARCHAR(40) NULL,
    location VARCHAR(180) NULL,
    github_url VARCHAR(255) NULL,
    linkedin_url VARCHAR(255) NULL,
    portfolio_url VARCHAR(255) NULL,
    instagram_url VARCHAR(255) NULL,
    languages VARCHAR(255) NULL,
    profile_completion TINYINT UNSIGNED DEFAULT 0,
    visibility ENUM('publico', 'privado') DEFAULT 'privado',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT user_profiles_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_profiles_university_id_fk FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE SET NULL,
    INDEX user_profiles_university_id_index (university_id),
    INDEX user_profiles_career_index (career),
    INDEX user_profiles_graduation_year_index (graduation_year),
    INDEX user_profiles_visibility_index (visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE portfolio_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL UNIQUE,
    public_slug VARCHAR(100) NOT NULL UNIQUE,
    theme_color VARCHAR(20) DEFAULT '#4F46E5',
    is_public TINYINT(1) DEFAULT 0,
    color_blind_mode TINYINT(1) DEFAULT 0,
    allow_contact TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT portfolio_settings_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX portfolio_settings_is_public_index (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE education (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    university_id INT UNSIGNED NULL,
    institution_name VARCHAR(180) NOT NULL,
    degree VARCHAR(180) NOT NULL,
    field_of_study VARCHAR(180) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    is_current TINYINT(1) DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT education_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT education_university_id_fk FOREIGN KEY (university_id) REFERENCES universities(id) ON DELETE SET NULL,
    INDEX education_user_id_index (user_id),
    INDEX education_university_id_index (university_id),
    INDEX education_dates_index (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE experiences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    organization VARCHAR(180) NOT NULL,
    type ENUM('practica', 'empleo', 'voluntariado', 'actividad', 'otro') DEFAULT 'otro',
    start_date DATE NULL,
    end_date DATE NULL,
    is_current TINYINT(1) DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT experiences_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX experiences_user_id_index (user_id),
    INDEX experiences_type_index (type),
    INDEX experiences_dates_index (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE projects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(200) NOT NULL,
    category VARCHAR(120) NULL,
    summary VARCHAR(255) NULL,
    description TEXT NULL,
    repository_url VARCHAR(255) NULL,
    demo_url VARCHAR(255) NULL,
    image_path VARCHAR(255) NULL,
    documentation_path VARCHAR(255) NULL,
    status ENUM('idea', 'en_progreso', 'finalizado', 'pausado') DEFAULT 'idea',
    visibility ENUM('publico', 'privado') DEFAULT 'privado',
    started_at DATE NULL,
    finished_at DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT projects_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX projects_user_id_index (user_id),
    INDEX projects_category_index (category),
    INDEX projects_status_index (status),
    INDEX projects_visibility_index (visibility),
    UNIQUE KEY projects_user_slug_unique (user_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE certifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    issuer VARCHAR(180) NOT NULL,
    category VARCHAR(120) NULL,
    credential_id VARCHAR(160) NULL,
    credential_url VARCHAR(255) NULL,
    certificate_path VARCHAR(255) NULL,
    issued_at DATE NULL,
    expires_at DATE NULL,
    visibility ENUM('publico', 'privado') DEFAULT 'privado',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT certifications_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX certifications_user_id_index (user_id),
    INDEX certifications_category_index (category),
    INDEX certifications_issuer_index (issuer),
    INDEX certifications_visibility_index (visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE skill_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    type ENUM('tecnica', 'blanda', 'idioma', 'herramienta') DEFAULT 'tecnica',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX skill_categories_type_index (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT skills_category_id_fk FOREIGN KEY (category_id) REFERENCES skill_categories(id) ON DELETE SET NULL,
    INDEX skills_category_id_index (category_id),
    INDEX skills_name_index (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE user_skills (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    proficiency ENUM('basico', 'intermedio', 'avanzado', 'experto') DEFAULT 'basico',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT user_skills_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_skills_skill_id_fk FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE,
    INDEX user_skills_user_id_index (user_id),
    INDEX user_skills_skill_id_index (skill_id),
    INDEX user_skills_proficiency_index (proficiency),
    UNIQUE KEY user_skills_unique (user_id, skill_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE project_skills (
    project_id BIGINT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (project_id, skill_id),
    CONSTRAINT project_skills_project_id_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT project_skills_skill_id_fk FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE project_screenshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    project_id BIGINT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    alt_text VARCHAR(180) NULL,
    sort_order SMALLINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT project_screenshots_project_id_fk FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    INDEX project_screenshots_project_id_index (project_id),
    INDEX project_screenshots_sort_order_index (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE certification_skills (
    certification_id BIGINT UNSIGNED NOT NULL,
    skill_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (certification_id, skill_id),
    CONSTRAINT certification_skills_certification_id_fk FOREIGN KEY (certification_id) REFERENCES certifications(id) ON DELETE CASCADE,
    CONSTRAINT certification_skills_skill_id_fk FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE resumes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    template_name VARCHAR(100) DEFAULT 'profesional',
    accent_color VARCHAR(20) DEFAULT '#4F46E5',
    pdf_path VARCHAR(255) NULL,
    status ENUM('borrador', 'generado', 'publicado') DEFAULT 'borrador',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT resumes_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX resumes_user_id_index (user_id),
    INDEX resumes_status_index (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE recommendations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    content TEXT NOT NULL,
    category ENUM('perfil', 'cv', 'habilidades', 'proyectos', 'becas', 'carrera') DEFAULT 'perfil',
    priority ENUM('baja', 'media', 'alta') DEFAULT 'media',
    is_completed TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT recommendations_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX recommendations_user_id_index (user_id),
    INDEX recommendations_category_index (category),
    INDEX recommendations_priority_index (priority),
    INDEX recommendations_completed_index (is_completed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'exito', 'advertencia', 'error') DEFAULT 'info',
    read_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT notifications_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX notifications_user_id_index (user_id),
    INDEX notifications_read_at_index (read_at),
    INDEX notifications_created_at_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    due_at DATETIME NOT NULL,
    priority ENUM('baja', 'media', 'alta') DEFAULT 'media',
    status ENUM('pendiente', 'completado', 'cancelado') DEFAULT 'pendiente',
    related_url VARCHAR(255) NULL,
    last_notified_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT reminders_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX reminders_user_status_index (user_id, status),
    INDEX reminders_due_at_index (due_at),
    INDEX reminders_priority_index (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE saved_candidates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recruiter_id INT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT saved_candidates_recruiter_id_fk FOREIGN KEY (recruiter_id) REFERENCES recruiters(id) ON DELETE CASCADE,
    CONSTRAINT saved_candidates_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX saved_candidates_recruiter_id_index (recruiter_id),
    INDEX saved_candidates_user_id_index (user_id),
    UNIQUE KEY saved_candidates_unique (recruiter_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE contact_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    sender_name VARCHAR(160) NOT NULL,
    sender_email VARCHAR(180) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('nuevo', 'leido', 'respondido', 'archivado') DEFAULT 'nuevo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT contact_messages_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX contact_messages_user_id_index (user_id),
    INDEX contact_messages_sender_email_index (sender_email),
    INDEX contact_messages_status_index (status),
    INDEX contact_messages_created_at_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(120) NOT NULL,
    table_name VARCHAR(120) NULL,
    record_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT audit_logs_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX audit_logs_user_id_index (user_id),
    INDEX audit_logs_action_index (action),
    INDEX audit_logs_table_record_index (table_name, record_id),
    INDEX audit_logs_created_at_index (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE announcements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_by BIGINT UNSIGNED NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    type ENUM('info', 'exito', 'advertencia', 'error') DEFAULT 'info',
    is_active TINYINT(1) DEFAULT 1,
    visible_from DATETIME NULL,
    visible_until DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT announcements_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX announcements_active_index (is_active),
    INDEX announcements_dates_index (visible_from, visible_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE moderation_flags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_user_id BIGINT UNSIGNED NULL,
    target_user_id BIGINT UNSIGNED NULL,
    content_type ENUM('perfil', 'proyecto', 'certificacion', 'mensaje', 'portafolio', 'otro') DEFAULT 'otro',
    content_id BIGINT UNSIGNED NULL,
    reason TEXT NOT NULL,
    status ENUM('pendiente', 'revisado', 'descartado', 'accion_tomada') DEFAULT 'pendiente',
    action_taken TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT moderation_flags_reporter_fk FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT moderation_flags_target_fk FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT moderation_flags_reviewer_fk FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX moderation_flags_status_index (status),
    INDEX moderation_flags_content_index (content_type, content_id),
    INDEX moderation_flags_target_index (target_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE system_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    description VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT system_settings_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE portfolio_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    visitor_ip VARCHAR(45) NULL,
    visitor_agent VARCHAR(255) NULL,
    referrer VARCHAR(255) NULL,
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT portfolio_visits_user_id_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX portfolio_visits_user_date_index (user_id, visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

INSERT INTO roles (name, display_name, description) VALUES
('estudiante', 'Estudiante', 'Usuario principal que construye su portafolio académico.'),
('administrador', 'Administrador', 'Gestiona usuarios, contenido y configuración del sistema.'),
('reclutador', 'Reclutador', 'Busca y guarda candidatos con portafolios públicos.');

INSERT INTO skill_categories (name, type) VALUES
('Programación', 'tecnica'),
('Diseño', 'tecnica'),
('Comunicación', 'blanda'),
('Idiomas', 'idioma'),
('Herramientas digitales', 'herramienta');

INSERT INTO system_settings (setting_key, setting_value, description) VALUES
('site_name', 'Portafolio Académico Inteligente', 'Nombre público de la plataforma.'),
('maintenance_mode', '0', 'Activa o desactiva modo mantenimiento.'),
('allow_public_registration', '1', 'Permite nuevos registros públicos.'),
('default_portfolio_visibility', 'privado', 'Visibilidad por defecto para portafolios nuevos.'),
('certification_categories', '["Programación","Diseño","Idiomas","Ciberseguridad","Datos","Nube","Productividad","Habilidades blandas","Otro"]', 'Categorías sugeridas para certificaciones.');

INSERT INTO universities (name, city, state, country) VALUES
('CECyTEM Plantel Demo', 'Ciudad de México', 'CDMX', 'México');

INSERT INTO users (role_id, full_name, username, email, password_hash, status, email_verified_at) VALUES
(2, 'Administrador Demo', 'admin', 'admin@portafolio.local', '$2y$10$2cCbyLXgSvN2cMhbsa4FFeGH6.XQCLAeUkjWg9QRFLFOSd.OoFEHi', 'activo', NOW()),
(1, 'Estudiante Demo', 'estudiante', 'estudiante@portafolio.local', '$2y$10$2cCbyLXgSvN2cMhbsa4FFeGH6.XQCLAeUkjWg9QRFLFOSd.OoFEHi', 'activo', NOW()),
(3, 'Reclutador Demo', 'reclutador', 'reclutador@portafolio.local', '$2y$10$2cCbyLXgSvN2cMhbsa4FFeGH6.XQCLAeUkjWg9QRFLFOSd.OoFEHi', 'activo', NOW());

INSERT INTO user_profiles (user_id, university_id, career, about_me, visibility) VALUES
(2, 1, 'Administración de sistemas', 'Cuenta administrativa de demostración.', 'privado'),
(1, 1, 'Desarrollo de software', 'Estudiante demo con portafolio académico activo.', 'publico'),
(3, 1, 'Recursos Humanos', 'Reclutador demo para pruebas del portal.', 'privado');

INSERT INTO portfolio_settings (user_id, public_slug, theme_color, is_public, allow_contact) VALUES
(2, 'admin', '#4F46E5', 0, 1),
(1, 'estudiante', '#4F46E5', 1, 1),
(3, 'reclutador', '#4F46E5', 0, 1);

INSERT INTO recruiters (company_name, contact_name, email, status) VALUES
('Empresa Demo', 'Reclutador Demo', 'reclutador@portafolio.local', 'activo');
