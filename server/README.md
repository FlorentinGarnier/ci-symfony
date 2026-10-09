# Serveur : installation d'une application

Le serveur porte la logique de déploiement ; GitHub ne peut qu'y demander,
par une clé limitée à une commande forcée, le déploiement d'une paire d'images par digest ou un retour arrière.
Le code n'est pas cloné sur le serveur, aucune dépendance n'y est installée, et la CI n'y copie aucun fichier.

Prérequis : Linux, Bash, `flock`, Docker Engine avec Compose v2 récent (`--wait`), Traefik avec le provider Docker,
DNS des domaines vers le serveur. Dans la suite, `<appli>` est le nom court de l'application (ex. `boutique`).

## Une fois par application

```bash
# Scripts communs, nommés d'après l'application (le nom est déduit du nom du fichier)
sudo install -o root -g root -m 755 server/app-deploy /usr/local/bin/<appli>-deploy
sudo install -o root -g root -m 755 server/app-ssh    /usr/local/bin/<appli>-ssh

# Compte de déploiement : membre du groupe docker (équivalent root : protéger strictement ses clés)
sudo adduser --disabled-password <appli>-deploy && sudo usermod -aG docker <appli>-deploy
sudo install -d -o <appli>-deploy -m 700 /srv/<appli> /srv/<appli>/staging /srv/<appli>/production

# Préfixe GHCR autorisé, en minuscules (lu par les deux scripts)
echo 'ghcr.io/<propriétaire>/<dépôt>' | sudo -u <appli>-deploy tee /srv/<appli>/image-prefix

# Lecture des images privées : jeton dédié read:packages
sudo -u <appli>-deploy -H docker login ghcr.io -u <compte> --password-stdin
```

Clés SSH : une paire par environnement, générée hors du serveur (`ssh-keygen -t ed25519 -N '' -f <appli>-staging`).
Dans `~<appli>-deploy/.ssh/authorized_keys` :

```
command="/usr/local/bin/<appli>-ssh staging",restrict ssh-ed25519 AAAA… github-<appli>-staging
command="/usr/local/bin/<appli>-ssh production",restrict ssh-ed25519 AAAA… github-<appli>-production
```

Ajouter le compte à `AllowUsers` de sshd si cette directive est utilisée. Vérifier qu'une commande arbitraire est refusée :
`ssh -i <appli>-staging <appli>-deploy@<hôte> id` doit répondre « Seuls … sont autorisés ».

## Par environnement

Dans `/srv/<appli>/staging` et `/srv/<appli>/production` (fichiers en mode 600, propriétaire `<appli>-deploy`) :

- `compose.yaml` : copie de `deploy/compose.yaml` du projet ;
- `stack.env` depuis `deploy/stack.env.example`, `app.env` depuis `deploy/app.env.example` ;
- staging : `htpasswd -B -c htpasswd recette` et `AUTH_BASIC=Recette` ; production : `AUTH_BASIC=off`.

Noms de projet Compose, domaines, mots de passe et secrets distincts entre les deux environnements.
Une évolution de `compose.yaml` s'installe à la main sur chaque environnement, avant le déploiement qui en a besoin.

Premier déploiement d'une base existante : créer `MIGRATION_PENDING` dans l'environnement pour bloquer tout
déploiement, importer la base, puis supprimer le fichier.

## Ce que fait `<appli>-deploy`

1. verrou `/srv/<appli>/deploy.lock` (un déploiement à la fois sur le serveur, lancements manuels compris) ;
2. contrôle des images : préfixe autorisé, digest ; en production, seulement la paire validée en staging
   (`staging/validated.env`) ou une paire déjà servie (`production/history.env`, pour un retour arrière) ;
3. sauvegarde de la base (PostgreSQL ou MySQL, détecté), 30 conservées, copie distante par `BACKUP_UPLOAD_CMD` ;
4. page de maintenance (503 + `Retry-After`) routée avant l'application, puis arrêt des services applicatifs ;
5. migrations, `messenger:setup-transports` si Messenger est installé, redémarrage ;
6. contrôles internes (`SELECT 1`, puis `HEALTHCHECK_PATH` via le conteneur web) ; s'ils échouent, la version en service
   est remise en route ; si une migration échoue, la maintenance reste active (inspecter le schéma avant toute reprise) ;
7. fin de la maintenance, mise à jour de `current.env`, `previous.env`, `validated.env` / `history.env`, `deployments.log`.

`<appli>-deploy <env> --rollback` remet `previous.env` en service sans migration : d'où la règle des migrations
toujours compatibles avec la version précédente.

## Limites connues

- La sauvegarde précède l'arrêt des services : des écritures peuvent survenir entre les deux.
- Les volumes (médias, uploads) ne sont pas sauvegardés par le script : prévoir une sauvegarde régulière, chiffrée,
  hors du serveur, et tester une restauration.
- `SELECT 1` et la sonde ne valident ni le schéma ni un parcours authentifié : la recette reste nécessaire.
