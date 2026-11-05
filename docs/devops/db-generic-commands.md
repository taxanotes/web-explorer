generically:

mysql -udatabaseusername -pdatabasepassword
drop DATABASE databasename;
create DATABASE databasename;
GRANT ALL PRIVILEGES ON databasename.* TO databaseuser@localhost IDENTIFIED BY 'databaseuserpassword';
mysql -udatabaseuser -pdatabaseuserpassword databasename < databasefile.sql
