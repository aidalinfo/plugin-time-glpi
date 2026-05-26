# Design : Alert Tracker

Date : 2026-05-26
Plugin : timetracker (GLPI 11)

---

## Contexte

Le plugin gère des budgets temps par contrat. L'utilisateur veut être alerté par email :
1. N jours avant la date de fin du contrat (ex. 60j, 30j)
2. Quand le budget temps atteint le seuil d'alerte déjà configuré

Les emails sont envoyés via le SMTP configuré dans GLPI (`GLPIMailer`). Un nouvel onglet "Alert tracker" s'ajoute sur le contrat (en plus de "Time budget").

---

## Architecture

- **Option retenue : Cron GLPI natif + GLPIMailer**
- Un `CronTask` enregistré quotidiennement via `plugin_timetracker_install_tasks()`
- Envoi direct via `GLPIMailer` (Symfony Mailer sous-jacent, utilise le SMTP GLPI)
- Pas de `NotificationTarget` complet (trop complexe pour ce besoin ciblé)

---

## Section 1 : Modèle de données

### Table `glpi_plugin_timetracker_alerts`

| Colonne | Type | Description |
|---|---|---|
| `id` | int unsigned PK AUTO_INCREMENT | |
| `contracts_id` | int unsigned NOT NULL DEFAULT 0 | FK vers glpi_contracts |
| `type` | varchar(20) NOT NULL | `'deadline'` ou `'threshold'` |
| `days_before` | int unsigned NULL | Jours avant fin (type=deadline uniquement) |
| `recipient_email` | varchar(255) NOT NULL | Email destinataire |
| `is_active` | tinyint NOT NULL DEFAULT 1 | |
| `last_sent_at` | datetime NULL | Timestamp dernier envoi réussi |
| `date_creation` | timestamp NULL | |
| `date_mod` | timestamp NULL | |

**Index :** `contracts_id`, `type`, `is_active`, `date_mod`, `date_creation`

### Règles métier

- `type = deadline` : envoi si `end_date - NOW() <= days_before jours` ET `last_sent_at` est NULL ou date de plus de 23h (pour éviter double envoi sur même cron)
- `type = threshold` : envoi si `spent_minutes >= alert_threshold_minutes` ET `last_sent_at` est NULL (ne se renvoie que si remis à NULL manuellement ou si le budget est réinitialisé)
- `end_date` = `begin_date + duration mois` (champs natifs du contrat GLPI)

---

## Section 2 : Onglet "Alert tracker" sur le contrat

### Classe `PluginTimetrackerAlertConfig` (nouveau fichier `inc/alertconfig.class.php`)

- Étend `CommonDBTM`
- `$rightname = 'contract'`
- Enregistrée via `Plugin::registerClass` avec `addtabon => ['Contract']`
- Méthode `getTabNameForItem()` → retourne `__tt('Alert tracker')`
- Méthode `displayTabContentForItem()` → appelle `showContractAlertTab()`

### UI de l'onglet

```
┌──────────────────────────────────────────────────────────────┐
│  Alert tracker                                               │
│                                                              │
│  Type        Days before  Email           Active   Actions  │
│  ─────────────────────────────────────────────────────────── │
│  Deadline    60           admin@acme.com  ✓        [Delete] │
│  Deadline    30           admin@acme.com  ✓        [Delete] │
│  Threshold   —            mgr@acme.com    ✓        [Delete] │
│  ─────────────────────────────────────────────────────────── │
│                                                              │
│  Add alert                                                   │
│  Type     [ Deadline ▼ ]  Days before [ 30 ]               │
│  Email    [________________________]                         │
│  Active   [✓]                                               │
│                                    [ + Add ]                │
└──────────────────────────────────────────────────────────────┘
```

- Tableau Bootstrap avec `p-2` sur les cellules
- Formulaire d'ajout en dessous du tableau
- Champ "Days before" masqué si type = Threshold (JS inline)
- Bouton Delete : `btn btn-sm btn-outline-danger` avec icône `ti ti-trash`
- Bouton Add : `btn btn-primary` avec icône `ti ti-plus`

---

## Section 3 : Cron quotidien

### Enregistrement dans `setup.php`

```php
function plugin_timetracker_install_tasks(): bool {
    CronTask::register(
        'PluginTimetrackerAlertConfig',
        'sendAlerts',
        DAY_TIMESTAMP,
        ['comment' => 'Send timetracker contract alerts', 'state' => CronTask::STATE_WAITING]
    );
    return true;
}
```

### Méthode `PluginTimetrackerAlertConfig::cronSendAlerts(?CronTask $task)`

```
Pour chaque alerte active :
  si type = deadline :
    calculer end_date = begin_date + duration mois
    si end_date - NOW() <= days_before jours ET last_sent_at est vieux/null :
      envoyer email
      mettre à jour last_sent_at
  si type = threshold :
    si spent_minutes >= alert_threshold_minutes ET last_sent_at est null :
      envoyer email
      mettre à jour last_sent_at
```

- `CronTask::register` premier argument = itemtype (nom de classe)
- La méthode cron doit être nommée `cron` + ucfirst(nom) → `cronSendAlerts`

---

## Section 4 : Envoi email via GLPIMailer

```php
$mailer = new GLPIMailer();
$mailer->getEmail()
    ->to(new Address($recipient_email))
    ->subject($subject)
    ->text($body);
$mailer->send();
```

### Corps des emails

**Deadline** :
- Sujet : `[GLPI Timetracker] Contrat "{nom}" — échéance dans {X} jours`
- Corps : nom du contrat, date de fin, jours restants, lien vers le contrat

**Threshold** :
- Sujet : `[GLPI Timetracker] Budget seuil atteint — contrat "{nom}"`
- Corps : nom du contrat, budget initial, temps consommé, seuil, temps restant

---

## Section 5 : Formulaires front

### `front/alertconfig.form.php`

- Actions : `add` (ajout d'alerte), `delete` (suppression)
- Vérifie `Session::checkRight('contract', UPDATE)`
- Redirige vers le contrat après action

---

## Fichiers créés/modifiés

| Fichier | Action |
|---|---|
| `inc/alertconfig.class.php` | Créer — classe principale, tab, cron |
| `front/alertconfig.form.php` | Créer — handler add/delete |
| `hook.php` | Modifier — ajouter table alertconfig dans install/uninstall |
| `setup.php` | Modifier — enregistrer classe + cron task, bump version 0.1.5 |
| `plugin.xml` | Modifier — bump version 0.1.5 |
| `locales/timetracker.pot` | Modifier — nouvelles chaînes |
| `locales/fr_FR.po` + `.mo` | Modifier — traductions FR |
| `locales/es_ES.po` + `.mo` | Modifier — traductions ES |

---

## Nouvelles chaînes i18n

| EN | FR | ES |
|---|---|---|
| Alert tracker | Alertes | Rastreador de alertas |
| Deadline | Échéance | Vencimiento |
| Threshold | Seuil budget | Umbral presupuesto |
| Days before | Jours avant | Días antes |
| Recipient email | Email destinataire | Email destinatario |
| Alert added. | Alerte ajoutée. | Alerta añadida. |
| Alert deleted. | Alerte supprimée. | Alerta eliminada. |
| days remaining | jours restants | días restantes |
| Budget threshold reached | Seuil budget atteint | Umbral de presupuesto alcanzado |
| Add alert | Ajouter une alerte | Añadir alerta |

---

## Hors périmètre

- Pas de template HTML pour les emails (texte brut suffisant)
- Pas de log d'historique des envois (last_sent_at suffit)
- Pas de modification de la table contractbudgets (threshold lu depuis l'existant)
