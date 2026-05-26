# Installation

Ce plugin est compatible avec GLPI `11.0.x`.

## Depuis la dernière release (recommandé)

### Téléchargement

```bash
curl -L -o timetracker.zip \
  https://github.com/aidalinfo/plugin-time-glpi/releases/latest/download/timetracker.zip
```

Ou directement depuis le navigateur :
[https://github.com/aidalinfo/plugin-time-glpi/releases/latest/download/timetracker.zip](https://github.com/aidalinfo/plugin-time-glpi/releases/latest/download/timetracker.zip)

### Installation

1. Extraire l'archive dans le dossier `plugins` de GLPI :

   ```bash
   unzip timetracker.zip -d /path/to/glpi/plugins/
   ```

2. Vérifier que le chemin final est exactement :

   ```text
   /path/to/glpi/plugins/timetracker/setup.php
   ```

3. Depuis GLPI, aller dans **Configuration > Plugins**.
4. Installer puis activer **Contract time tracking**.

## Depuis Git

```bash
cd /path/to/glpi/plugins
git clone https://github.com/aidalinfo/plugin-time-glpi.git timetracker
```

Puis installer et activer le plugin depuis **Configuration > Plugins**.

## Vérification CLI

Depuis la racine GLPI :

```bash
php bin/console plugin:install timetracker --username=glpi
php bin/console plugin:activate timetracker
php bin/console plugin:list
```

Le plugin crée deux tables :

```text
glpi_plugin_timetracker_contractbudgets
glpi_plugin_timetracker_timeentries
```

## Utilisation

1. Ouvrir un contrat GLPI.
2. Dans l'onglet **Time budget**, définir le temps initial et le seuil d'alerte en minutes ou en heures.
3. Ouvrir un ticket GLPI.
4. Dans l'onglet **Contract time**, saisir le temps passé en minutes ou en heures.
5. Ouvrir **Tools > Contract time** pour consulter le dashboard par contrat.

Les valeurs sont toujours stockées en minutes dans la base du plugin.
