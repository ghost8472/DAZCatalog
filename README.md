# DAZCatalog
A way to store your personal DAZ3D library, for sharing online, or viewing offline.

Copyright (c) 2018 William Baker, Ether Tear LLC

## Description

This is for keeping a personal catalog of your DAZ 3D assets, to facilitate the following:
* Searching a bit more powerfully (in description, store page, or your own personal tags)
* Sharing with colleagues (put this on a webserver, and allow them to tag things)
* Offline access (put this on your own computer to view it when you have no internet access)


## Installation:

For all scenarios:
* Place this on your webserver
** Drop this onto a folder (or git clone this into a folder) that will serve PHP.
** If you need help, there are some suggestions below
* Access that folder/page from a web browser.
* Load in your personal catalog:
** Follow the instructions to make it aware of what products you have.
** Follow the instructions to load in the Product Library description of those products.
** Allow it to refresh until all products are cached (downloading image and store page).
* View your catalog:
** Search for words found in the title, description, and/or store pages
** Add your own tags, then use those to search
  
"Place this on your webserver" for sharing with colleagues:
* Access your webserver (varies based on who manages it, often you can use SFTP to access it with an FTP Client, such as FileZilla).
* Create a directory for this purpose, and place this git repo into it.
* Using a web browser, access that directory (to view index.php), and you should see the loader page.
* Tips for better security:
** Use some access control, such as `.htaccess` to prevent random people from being able to view the catalog
** Avoid having any links to the folder, so that it will not show up on search engines

"Place this on your webserver" for using offline:
* Install a webserver on your computer:
** Linux users:
*** You likely already have this installed, and can put webpages into your `/var/www/html/` folder.
*** Enabling the webserver daemon will differ based on which version of Linux you use, sorry.
** Windows users:
*** You will need to install something, such as WAMP or XAMPP
*** For example, with WAMP you will store the files in `C:\wamp\www` and start the daemon from your Start Menu
* Install this program by just dropping it into a folder inside of where your webserver serves from.
** For example, we will name that folder "DAZCatalog" since that is the git default way of naming a cloned project.
* Access it by using a web browser, pointing to `localhost` which will look something like:
** `http://localhost/DAZCatalog`
** On first running it, it should take you to the loader page.
* For added security:
** Configure your webserver to only serve on "localhost"
** Use something like `.htaccess` to restrict it to being served just to local access (deny remote access)

### Advanced configuration

You can modify config.php (create a copy of `config.template.php`) to modify some of the settings at the top of index.php while still retaining the ability to do a `git pull` to update it later.

See `config.template.php` for some examples.


## Using the search abilities

The searching/filtering abilities should be rather familiar, but it does have some quirks.

There is a "Show Search Instructions" link to click on, and it will remind you of what you can do while searching.
* The "words" index includes all the words from the Product Library description, and the store page.
* The "tags" index includes just the tags that you have assigned to items.
* Search results are remembered in your session, for quicker refreshes.
** If you change some tags, and an item would no longer be part of the search, click "New Search" to reprocess the search.

The "Skip Tags" are tags that are useful for making items not show up on your searches.
You can modify these via config.php if you want to add/subtract items from this list.  The defaults are:
* "bundle" - a collection of items, but itself not being any items.
* "tex" - textures that apply to some other item in your library.
* "fits" - outfit "unimesh fits" that apply to some other outfit in your library.
* "poses" - poses that apply to some other character in your library.
* "scripts" - things that are not items that can appear in your project, such as a script to post-process it.





## Bookmarks
Useful browser bookmarks, originally based on a forum post (sorry, I lost the link), and then modified.

To use these, paste their code into the "URL" part of your browser's bookmarks.  Try creating a bookmark first, then edit it, and replace the URL with this code.

These will all turn your current "Platinum Club" checkbox into a checkbox which will trigger the new logic instead.  So, the steps are:
  1) be viewing a page that has the "Platinum Club" checkbox
  2) click your bookmark
  3) notice the "Platinum Club" checkbox has a new name
  4) click the new checkbox to apply the new filters.
  5) leaving, or reloading, the page will return it to its original "Platinum Club" filter logic.

Wishlist button
```
	javascript:(function(){var obj = $($('#large_platClub')[0]);obj.html([obj.children(), 'Wishlist']);obj[0].id = 'large_wishlist'; var info = daz.api.data['User/info']; var yes = {}; for(var i=0; i<info.wishlistCount; i++) { yes[info.wishlistItems[i]] = true; }; daz.filter.filters.platClub.yes = yes;})();
```
DAZ Originals button (for using the $6 a month free coupon)
```
	javascript:(function(){ var obj = $($('#large_platClub')[0]);obj.html([obj.children(), 'DAZ Coupon ($6 free) Wishlist, no ']);  var wishes = daz.api.data['User/info'].wishlistItems; var wish = {}; for(var i=0; i<wishes.length; i++) { wish[wishes[i]] = true; }; var dazos = daz.api.data.FilterData_Filters.vendor['Daz Originals']; var dazo = {}; for(var i=0; i<dazos.length; i++) { dazo[dazos[i]] = true; } var platos = daz.api.data.FilterData_Filters.platClub['yes']; var plato = {}; for(var i=0; i<platos.length; i++) { plato[platos[i]] = true; }  var keepers = {}; for (var pid in wish) { if (typeof plato[pid] != 'undefined') { continue; } if (typeof dazo[pid] == 'undefined') { continue; } keepers[pid] = true; }  daz.filter.filters.platClub.yes = keepers; })();
```
Platinum Club $6 off of $18 coupon button:
```
	javascript:(function(){ var obj = $($('#large_platClub')[0]);obj.html([obj.children(), 'DAZ/PA Coupon ($18) Wishlist, no ']); var wishes = daz.api.data['User/info'].wishlistItems; var wish = {}; for(var i=0; i<wishes.length; i++) { wish[wishes[i]] = true; }; var dazos = daz.api.data.FilterData_Filters.vendor['Daz Originals']; var dazo = {}; for(var i=0; i<dazos.length; i++) { dazo[dazos[i]] = true; } var platos = daz.api.data.FilterData_Filters.platClub['yes']; var plato = {}; for(var i=0; i<platos.length; i++) { plato[platos[i]] = true; } var newsies = daz.api.data.FilterData_Filters.new.yes; var newsy = {}; for(var i=0; i<newsies.length;i++) { newsy[newsies[i]] = true; } var keepers = {}; for (var pid in wish) { if (typeof plato[pid] == 'undefined' && typeof dazo[pid] == 'undefined') { continue; } if (typeof newsy[pid] != 'undefined') { continue; } keepers[pid] = true; } daz.filter.filters.platClub.yes = keepers; })();
```