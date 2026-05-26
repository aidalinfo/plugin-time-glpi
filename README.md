# Contract time tracking for GLPI

Plugin key: `timetracker`

This plugin targets GLPI 11 and stores all consumed time in minutes. It lets you:

- define an initial time budget on each contract;
- define an alert threshold in minutes;
- add spent time from a ticket in minutes or hours;
- see consumed and remaining time per contract;
- open a dashboard from the Tools menu.

## Installation

See [docs/INSTALLATION.md](docs/INSTALLATION.md).

Short version — download the latest release and extract it:

```bash
curl -L -o timetracker.zip \
  https://github.com/aidalinfo/plugin-time-glpi/releases/latest/download/timetracker.zip
unzip timetracker.zip -d /path/to/glpi/plugins/
```

Then install and enable **Contract time tracking** from **Setup > Plugins**.

The deployed GLPI plugin folder must be named `timetracker`. The plugin declares GLPI compatibility from `11.0.0` included to `11.1.0` excluded.

## Usage

1. Open a GLPI contract.
2. Use the **Time budget** tab to set the initial time and alert threshold in minutes or hours, and whether tracking is active.
3. Open a ticket.
4. Use the **Contract time** tab to select a configured contract and enter time in minutes or hours. If the ticket already has a linked contract with an active plugin budget, it is selected by default.
5. Open **Tools > Contract time** to see totals, remaining time, and threshold/over-budget status.

## Build ZIP

The GitHub Actions workflow `.github/workflows/build.yml` builds `dist/timetracker.zip`.
The archive contains a top-level `timetracker/` folder ready to extract into GLPI's `plugins` directory.

## Data model

- `glpi_plugin_timetracker_contractbudgets`: one budget row per GLPI contract.
- `glpi_plugin_timetracker_timeentries`: time entries linked to GLPI tickets, contracts, and users.

## License

MIT
