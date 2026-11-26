#!/bin/bash
# Fetches the plugin that some tests of this plugin need, into the Matomo the tests run in.
# Runs in the Matomo directory. If it cannot be fetched, its tests skip.
set -u

rm -rf plugins/RebelFunnels
git clone --quiet --depth=1 https://github.com/Digitalist-Open-Cloud/RebelFunnels plugins/RebelFunnels \
  && echo "Fetched RebelFunnels." || echo "Could not fetch RebelFunnels, its tests will be skipped."
