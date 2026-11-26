# Plan

What is left to do in RebelPaths, in the order it is worth doing.

## Before a first release

- Release the change in RebelFunnels that tells which visits completed a funnel
  (`FunnelCounter::getCompletions`). Until then RebelPaths offers no funnels and its funnel tests
  skip.
- Try it on a website with real traffic and note how long these take:
  - archiving a day, which reads the day's page views twice, once by URL and once by title;
  - "Around a page" and "On the way to a goal", which read the logs when a page or goal is picked.
- Look at the flow diagram on that website. Six pages for each step is a guess, and so is the
  room a name gets.
- Add a `screenshots/` folder with screenshots for the Marketplace.

## Next features

Pick by what the first users run into.

### Group pages

Show pages that are alike as one step, such as every blog post as "Blog post". Without it the
flow of a large website is split over hundreds of addresses and most of a step is "Other pages".

- A setting for each website: rules of a pattern and a group name, the first match decides.
- Apply it where a step gets its label, so the archived paths and the live views agree.
- Asking for the pages around a group means finding every page the group matches.
- Changing the rules builds the archived paths again.

### Events as steps

For websites where what matters is a click or a video and not a page view. An event has the
position of the page it happened on, so it needs an order of its own within a page.

### Settings for the limits

These are fixed in the code. Make them settings when someone asks for another value.

| Limit | Value | Where |
|---|---|---|
| Steps of a path | 5 | `RecordBuilders\Paths::STEPS` |
| Paths kept for a day | 20,000 | `RecordBuilders\Paths::MAX_PATHS` |
| Next steps kept under a step | 50 | `RecordBuilders\Paths::MAX_NEXT_STEPS` |
| Pages of a step in the explorer | 20 | `PathsPage::MAX_NEXT_STEPS` |
| Steps sent with a page | 3,000 | `PathsPage::MAX_STEPS_IN_PAGE` |
| Pages of a step in the flow | 6 | `FLOW_PAGES` in `javascripts/pathsPage.js` |
| Pages of a step around a page or goal | 8 | `PagesAround::MAX_PAGES_OF_A_STEP` |
| Pages on the way to a goal | 10 | `PagesAround::MAX_PAGES_ON_THE_WAY` |

Changing the steps of a path changes what is archived, so it builds the data again.

### Smaller things

- What visits viewed before they entered a funnel. Today a funnel is counted from where visits
  completed it.
- Count a visit that reaches the last step of a funnel without an action, as with a form step.
- Load a step of the explorer when it is picked, in place of sending the steps with the page, if
  a website has more next steps worth showing than fit.
- Archive the pages around the most visited pages and goals, if reading the logs on demand is too
  slow.

## Known limits

- A visit that goes on past midnight ends its path at midnight.
- A segment with two conditions on pages joined by "and" matches one page view that meets both,
  as everywhere in Matomo.
