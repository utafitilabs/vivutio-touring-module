# vivutio/touring-module

**Tours for vivutio.** An itinerary written day by day through the core's
destinations, where each night is spent, what each day includes, and what the
parks charge a party starting on a day.

## Contents

- [Install](#install)
- [How it works](#how-it-works)
- [Who may do what](#who-may-do-what)
- [Development](#development)
- [Licence](#licence)

## Install

In a vivutio installation:

```bash
composer require vivutio/touring-module
php bin/console doctrine:migrations:migrate
```

Its recipe registers the bundle and mounts its pages with
`config/routes/touring.yaml`, a file the installation owns.

## How it works

A **tour** has a name, a sentence about it, the group it is sized for, what it
includes and leaves out, and its days in order. It is a draft until it opens
for sale, which needs at least one day, and is archived when it is sold no
more.

A **day** has a title, up to three destinations from the core's list (Serengeti
National Park, by its key `tz-serengeti`), where its night is spent, the meals
it includes, what happens, and how far and how long it drives. The night is
spent at a place an installed package offers, a property for instance, through
the core's place contract, or at an accommodation partner the organization
trades with now, through the core's partner contract. The module names no
other module.

**Park fees.** For a party starting on a day (adults, children, residency),
each day's destinations are priced from the fees the organization entered in
the core: a fee a person a day every day; a fee a person an entry and a vehicle
an entry on the first day of each visit, a visit being the days in a row the
tour is there. The party travels in one vehicle. A destination with no fee in
force is named, so nothing is priced at nothing unnoticed.

## Who may do what

| Pair | Who |
|---|---|
| `tours.read` | The register, a tour's page and its park fees |
| `tours.manage` | Adding and configuring tours, writing their days, opening and archiving |

Both are a module's pairs, held where the person's department, or one they
support, allows them too. The suite extends the core's authority test base;
`tests/authority-table.md` is the reviewed table.

## Development

```bash
composer update
composer check
```

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
