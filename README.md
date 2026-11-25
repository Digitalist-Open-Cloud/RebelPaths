# Rebel Paths

[![PHPCS check](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/phpcs.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/phpcs.yaml)
[![Semgrep OSS scan](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/semgrep.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/semgrep.yaml)
[![Test plugin with Matomo](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/matomo.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/matomo.yaml)
[![PHPStan check](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/phpstan.yaml/badge.svg)](https://github.com/Digitalist-Open-Cloud/RebelPaths/actions/workflows/phpstan.yaml)

A Matomo plugin that shows the paths visits take through a website: the page they start on, the
pages they go to next, step by step, and where they leave.

## Install

Activate the plugin. It needs no change to the tracking code. The reports are filled when Matomo
archives; to fill them for days that are archived already, run:

```sh
./console core:invalidate-report-data --dates=<from>,<to> --plugin=RebelPaths
./console core:archive
```

## The page

**Behaviour > Paths** shows the paths by the address of a page or by its title.

- **Top paths** lists the most common whole paths: the pages a visit viewed, in order, until it
  left, and how many visits took exactly that route.
- **Flow** is a diagram with a column for each step and a bar for each page, as tall as its
  visits. Bands show where visits went next, and the red end of a bar is the visits that left
  there. Click a page to light up what leads to it and from it. A step shows its six most visited
  pages; the rest are one bar.
- **Step explorer** starts with the pages visits began on. Pick one and the next column shows
  where those visits went; pick again to go on, up to five steps. Each page shows its visits and
  the share that left there.
- **Around a page** takes a page you pick and shows what visits viewed in the two steps before
  they first came to it and in the two steps after, with how many started and how many left
  there. Click a page in the columns to look around that one. This part is read from the logs
  when you pick a page, so it takes longer on a long period.

How it counts:

- A segment narrows the paths to its visits, for example visits from a campaign or visits that
  converted a goal.
- A search on the website is a step of its own, "Site search". Events, downloads and outlinks are
  not steps.
- A page that is loaded again is a step, so a page can follow itself.
- A page of the website's own address is shown without the host. A page of another host keeps it.

## Limits

- A path has five steps. Visits that went further are counted at the fifth step.
- For each day the most common paths are kept. What is left of a step is "Other pages".
- The explorer shows the 20 most visited pages of a step.
- A visit that goes on past midnight ends its path at midnight.

## API

`RebelPaths.getPaths` and `RebelPaths.getPathsByTitle` return the paths as a table with the next
steps under each row. They take `idSite`, `period`, `date`, `segment`, `expanded`, `flat` and
`idSubtable`, and need view access to the website. Each row has the visits that came this far
(`nb_visits`) and those that left there (`nb_path_exits`).

`RebelPaths.getPagesAroundPage` takes `idSite`, `period`, `date`, `page`, `byTitle` and `segment`.
`page` is the page as the paths show it, such as `/pricing`, or its title with `byTitle=1`. It
returns the visits that came to the page, how many started and left there, and the pages of the
steps -2, -1, 1 and 2.

## Development

```sh
./console tests:run RebelPaths
./console tests:run-ui --plugin=RebelPaths
```
