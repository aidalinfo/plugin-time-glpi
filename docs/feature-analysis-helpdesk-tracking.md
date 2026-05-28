# Analyse — Suivi des contrats Helpdesk Pulse myIT

Analyse du compte rendu *Suivi des contrats Helpdesk Pulse myIT* croisée avec
les capacités natives de GLPI 11 et l'état actuel du plugin `timetracker`.

## 1. Suivi de la consommation des heures

| Élément attendu | Natif GLPI | Plugin actuel | À ajouter |
|---|---|---|---|
| Volume d'heures souscrit | ❌ | ✅ (`contractbudget`) | — |
| Temps consommé / restant | ⚠️ (temps ticket natif, pas agrégé par contrat) | ✅ | — |
| Historique des consommations par ticket | ⚠️ (tâches / `actiontime`) | ✅ (`timeentry`) | — |
| Date début/fin contrat | ✅ (contrat GLPI) | — | — |
| Projection / tendance de consommation | ❌ | ❌ | **À ajouter** — calcul de run-rate + extrapolation fin de période |
| Accès facturation / commercial / client | ⚠️ (profils GLPI) | partiel (dashboard interne) | **À ajouter** — reporting périodique + vue client filtrée |

## 2. Suivi des déplacements

Rien de natif côté GLPI pour les km, motif de déplacement, site, tarif au km.

| Information | Statut |
|---|---|
| Date intervention, technicien, client, ticket lié | ✅ déductible des tickets natifs |
| Km parcourus, objet du déplacement, temps sur site | ❌ **À ajouter** (nouvel objet `travelentry` lié au ticket) |
| Tarif 0,73 €/km configurable | ❌ **À ajouter** (config plugin) |
| Export facturation déplacements | ❌ **À ajouter** |

➡️ Nécessite une nouvelle entité plugin (équivalent `timeentry` mais pour
déplacements) avec champs km, site de départ, motif, et un tarif km configurable.

## 3. Alertes de renouvellement (J-60)

| Élément | Natif GLPI | Plugin actuel | À ajouter |
|---|---|---|---|
| Notification fin de contrat | ✅ (alerte contrat native, délai paramétrable) | — | — |
| Destinataires multiples (commercial, RC, direction) | ✅ (NotificationTargets) | — | — |
| Contenu enrichi : heures conso/restantes, tendance, nb tickets, déplacements, historique support | ❌ | ❌ | **À ajouter** — notification custom avec template agrégeant les données plugin |

➡️ Le plugin a déjà un plan `2026-05-26-alert-tracker.md` — à étendre pour
gérer l'alerte renouvellement J-60 avec template enrichi.

## 4. Centralisation GLPI

| Donnée | Statut |
|---|---|
| Temps passé, interventions, dates, techniciens, catégories, criticité | ✅ natif (ticket + tasks) |
| Déplacements | ❌ à ajouter (cf. §2) |
| Tableau de bord client | ⚠️ dashboard plugin existe, à enrichir (vue par contrat / client) |
| Reporting automatique mensuel | ❌ **À ajouter** (cron + envoi PDF/CSV) |
| Exports dédiés facturation | ❌ **À ajouter** (export CSV heures + déplacements par période) |
| Indicateurs de rentabilité par contrat | ❌ **À ajouter** (coût technicien vs revenu forfait) |

## Synthèse — backlog à développer

**Déjà couvert par le plugin**

- Budget d'heures par contrat, décompte, seuil d'alerte, dashboard.

**À développer (par priorité estimée)**

1. **Suivi des déplacements** — nouvel objet + UI sur le ticket, tarif km configurable.
2. **Alerte de renouvellement J-60 enrichie** — extension du plan `alert-tracker` avec contenu agrégé.
3. **Projection / tendance de consommation** — calcul run-rate sur le `contractbudget`.
4. **Exports facturation** — CSV heures + déplacements filtrables par contrat / période.
5. **Reporting automatique mensuel** — cron + génération + envoi au client.
6. **Tableau de bord enrichi** par contrat / client avec indicateurs de rentabilité.

**Points natifs à exploiter sans dév**

- Dates contrat, liaison ticket↔contrat, catégories, criticité, techniciens,
  notifications de base, profils d'accès.
