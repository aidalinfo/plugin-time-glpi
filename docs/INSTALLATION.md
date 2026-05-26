# Installation

Ce plugin est compatible avec GLPI `11.0.x`.

## Depuis une archive ZIP

1. Telecharger l'artefact `timetracker.zip` genere par GitHub Actions.
2. Extraire l'archive dans le dossier `plugins` de GLPI.
3. Verifier que le chemin final est exactement :

   ```text
   /path/to/glpi/plugins/timetracker/setup.php
   ```

4. Depuis GLPI, aller dans **Configuration > Plugins**.
5. Installer puis activer **Contract time tracking**.

## Depuis Git

```bash
cd /path/to/glpi/plugins
git clone https://github.com/aidalinfo/plugin-time-glpi.git timetracker
```

Puis installer et activer le plugin depuis **Configuration > Plugins**.

## Verification CLI

Depuis la racine GLPI :

```bash
php bin/console plugin:install timetracker --username=glpi
php bin/console plugin:activate timetracker
php bin/console plugin:list
```

Le plugin cree deux tables :

```text
glpi_plugin_timetracker_contractbudgets
glpi_plugin_timetracker_timeentries
```

## Utilisation

1. Ouvrir un contrat GLPI.
2. Dans l'onglet **Time budget**, definir le temps initial et le seuil d'alerte en minutes ou en heures.
3. Ouvrir un ticket GLPI.
4. Dans l'onglet **Contract time**, saisir le temps passe en minutes ou en heures.
5. Ouvrir **Tools > Contract time** pour consulter le dashboard par contrat.

Les valeurs sont toujours stockees en minutes dans la base du plugin.

