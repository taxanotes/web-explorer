

after updating to maria 10.6

Failed to start myriatrix-dev: unable to start project myriatrix-dev because the configured database type does not match the current actual database. Please change your database type back to mariadb:10.4 and start again, export, delete, and then change configuration and start. To get back to existing type use 'ddev config --database=mariadb:10.4' and then you might want to try 'ddev debug migrate-database mariadb:10.6', see docs at https://ddev.readthedocs.io/en/stable/users/extend/database-types/ 


 1216  ddev drush sql-drop -y
 1217  ddev config
 1218  ddev start
 1219  ddev config
 1220  ddev start
 1221  ddev delete --omit-snapshot myriatrix-dev
 1222  ddev config
 1223  ddev start


--

 ddev seems to use local php so had to brew install 8.3 and unlink 8.1
 

why is my js not loading, from a Drupal 11 module
 https://claude.ai/share/bb8eb06f-fee8-4943-8d77-f51394712068