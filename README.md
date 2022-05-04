# humbleparser
Parse your humble choice / monthly data and compile a CSV list for better overview

## Requirements

* PHP CLI (>= 7.4))
* php-curl

## Note
I have never suspended my subscription since I have subscribed to Humble Monthly / Choice.\
I'm therefore unsure how this script will behave, if it encounters a month where you had no subscription.
It should not break, though.

## Usage

1. First you need to fetch your session cookie from Humble:\
   Login to Humble, open your Developer Console and fetch the `Cookie` string from the request header.
   It looks something like this:\
   `csrf_cookie=XXXX[...]`


2. Open HumbleParser.php, scroll to the bottom and insert the cookie string:\
   `$test->setCookie('csrf_cookie=Uu2p....');`


3. (optional) Enter a path where the cache should be created:\
    `$test->setCacheDir('/home/user/Desktop/humblecache/');`\
    You only need this, when using a cache (see #5), which is strongly recommended


4. Enter a path where the compiled CSV file should be placed:\
    `$test->setOutputDir('/home/user/Desktop/');`


5. (optional) Enable caching of downloaded data:\
    `$test->setUseCache(true);`\
    This is optional, but strongly recommended


6. Configure the year and month when your subscription has started:\
   `$test->setYearStart(2018);`\
   `$test->setMonthStart(7);`


7. Save the file and switch to a terminal and run the script:\
    `php HumbleParser.php`


8. Pray that it works, watch it doing stuff and enjoy your CSV file!


## Final notes

As always when you parse data from foreign sources, be nice!\
There is a sleep() command after each call to Humble and please leave it that way.\
Thank you!