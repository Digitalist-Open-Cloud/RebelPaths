# Changelog

## Unreleased

### Added

- "Paths" in the reporting menu, with a page for each view of the paths of visits, by page URL or by page title:
  - Top paths: the most common whole paths, from the first page of a visit until it left.
  - Flow: a diagram with a column for each step, a bar for each page and bands for where visits went next.
  - Step explorer: pick a page visits started on and see where they went next, step by step, with how many left at each step.
  - Around a page: pick a page and see what visits viewed in the two steps before and after it.
  - On the way to a goal: pick a goal and see the three steps before visits converted it, and for the pages on the way how many visits converted, left there or went elsewhere.
- With the plugin RebelFunnels, "On the way to a goal" also takes a funnel: the steps before visits completed it.
- A row of the reports of page URLs, entry pages and exit pages has an action that opens "Around a page" for that page.
- The API methods `RebelPaths.getPaths`, `RebelPaths.getPathsByTitle`, `RebelPaths.getPagesAroundPage` and `RebelPaths.getPagesBeforeGoal`.
