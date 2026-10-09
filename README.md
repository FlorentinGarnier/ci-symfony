# ci-symfony

CI/CD commune aux projets Symfony et Sylius : images immuables par digest,
staging automatique, production par GitHub Release, déploiement sous contrôle du serveur.
Les projets ne gardent que de courts fichiers d'appel. Ce dépôt est public pour être appelé depuis toutes
les organisations : il ne contient aucun secret.

## Chemin d'une version

```
PR   ──► contrôles ──► construction des images (non publiées)
main ──► contrôles ──► images php/web publiées par digest + manifeste ──► staging ──► tests de fumée
                                                                                     └─ échec ──► retour arrière
GitHub Release publiée sur un commit recetté ──► manifeste de ce commit ──► Trivy ──► production ──► tests de fumée
                                                                                                    └─ échec ──► retour arrière
Lancement manuel de « Production » avec une release existante ──► remise en service de cette version
```

Le serveur refuse en production toute paire d'images qui n'a pas été validée en staging ou déjà servie en production.

## Contenu

| Chemin | Rôle |
|---|---|
| `.github/workflows/ci.yml` | contrôles : Composer validate et audit, style, PHPStan, lint Twig/YAML/conteneur, assets ; base vide + migrations + `schema:validate`, PHPUnit, Behat |
| `.github/workflows/build.yml` | images `php` et `web` ; sur `main`, publication sur GHCR et manifeste de version |
| `.github/workflows/deploy.yml` | déploiement par SSH (commande forcée), tests de fumée, retour arrière si échec |
| `.github/workflows/release.yml` | release → commit → exécution CI réussie → manifeste → analyse Trivy |
| `.github/workflows/changelog.yml` | notes de release depuis la dernière release stable |
| `server/` | `app-deploy` et `app-ssh`, à installer sur le serveur ([installation](server/README.md)) |
| `template/` | fichiers à copier dans un projet |

### Détection automatique dans `ci.yml`

| Présent dans le projet | Contrôle |
|---|---|
| `ecs.php` / `.php-cs-fixer.dist.php` | ECS / PHP-CS-Fixer (avertissement si aucun) |
| `phpstan.neon*` / `phpstan.dist.neon` | PHPStan (avertissement si absent) |
| `templates/` | `lint:twig` |
| `phpunit.xml.dist` | PHPUnit |
| `vendor/bin/behat` et `features/**/*.feature` | Behat avec `behat-tags` |
| `yarn.lock` / `pnpm-lock.yaml` / `package-lock.json` | installation puis `build:prod` (ou `build`) |
| `importmap.php` | `importmap:install` + `asset-map:compile` |

Entrées : `php-version`, `php-extensions`, `database` (`mysql` | `postgres` | `none`), `database-setup`
(`migrations` | `schema` quand l'historique des migrations ne crée pas le schéma), `behat-tags`, `before-script`, `env`.

## Adopter la CI/CD dans un projet

1. Copier `template/` dans le projet : `.github/`, `Dockerfile`, `docker/`, `deploy/`, `src/Controller/HealthController.php`.
   Régler `.github/workflows/ci.yml`, seul fichier propre au projet.
2. Conventions :
   - **Dockerfile** avec les cibles `php` et `web` ; `web` contient `deploy/maintenance` en `/srv/maintenance` ;
     arguments `APP_VERSION` et `APP_COMMIT` exposés en variables d'environnement ;
   - **`/healthz`** renvoie du JSON avec `"status":"ok"` et `"commit":"<sha complet>"` (sinon `smoke-path`) ;
   - **staging** fermé à l'indexation (`robots.txt` en `Disallow: /`, `X-Robots-Tag: noindex`), production ouverte
     (sinon `check-indexing: false`) ;
   - **Compose** avec les services `database`, `php`, `web`, `maintenance`, derrière Traefik ;
   - migrations toujours compatibles avec la version précédente.
3. Serveur : voir [server/README.md](server/README.md).
4. GitHub :
   - environnements `staging` (branche `main`) et `production` (branche `main` et étiquettes `v*`) : variables
     `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PORT`, `BASE_URL` ; secrets `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`
     (clé publique du serveur vérifiée hors bande, jamais un `ssh-keyscan` au moment du déploiement), et `BASIC_AUTH`
     (`recette:<mot de passe>`) pour staging ;
   - protection de `main` : fusion par demande, contrôles « Qualité, analyse statique et assets »,
     « Base de données et tests » et « Images » obligatoires ;
   - création des releases réservée aux mainteneurs.

## Versions

Les projets appellent `@v1`. Une correction compatible est publiée en `v1.x.y` et l'étiquette `v1` est déplacée dessus :

```bash
git tag v1.2.0 && git tag -f v1 v1.2.0 && git push origin v1.2.0 && git push -f origin v1
```

Un changement incompatible (entrée renommée, convention modifiée) passe en `v2` ; les projets migrent un par un.
Les actions tierces sont épinglées par SHA ; Dependabot (écosystème `github-actions`) propose leurs mises à jour.
