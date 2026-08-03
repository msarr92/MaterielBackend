# Sécurité et tests

## Vérifications locales

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan test
vendor/bin/pint --test
composer audit
```

## Principales protections ajoutées

- inscription réservée aux administrateurs authentifiés ;
- contrôle des rôles côté API, qui reste la source de vérité ;
- blocage des comptes inactifs ;
- mots de passe d'au moins 12 caractères ;
- limitation des tentatives de connexion ;
- en-têtes HTTP de sécurité ajoutés aux réponses ;
- tests d'authentification et d'autorisation ;
- pipeline GitLab avec PHPUnit, Pint, audit Composer, SAST, détection des secrets et dépendances vulnérables.

## Variables GitLab recommandées

Dans **Settings > CI/CD > Variables**, ajouter les variables de production sans les écrire dans Git :

- `APP_KEY`
- `JWT_SECRET`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- `REDIS_HOST`, `REDIS_PASSWORD`
- les identifiants Kafka si Kafka est utilisé

Cochez **Masked** et **Protected** pour les secrets.
