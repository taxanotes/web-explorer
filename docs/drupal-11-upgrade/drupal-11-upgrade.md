https://drupalize.me/tutorial/upgrade-drupal-11#toc-quickstart-07Ku-nUk



```
composer require 'drupal/core-recommended:^11' 'drupal/core-composer-scaffold:^11' 'drupal/core-project-message:^11' --update-with-dependencies --no-update
# If you have drupal/core-dev installed
composer require 'drupal/core-dev:^11' --dev --update-with-dependencies --no-update
# If you have Drush installed (check recommended version)
composer require 'drush/drush:^13' --no-update
# Now, actually perform the update to the code itself.
composer update

# Then run any updates.
drush cr -y
drush updb

# Export configuration changes (and don't forget to commit the changes).
drush cex -y
```