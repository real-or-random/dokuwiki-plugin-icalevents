package_name = dokuwiki-plugin-icalevents
version=`awk '/date/{print $$2}' plugin.info.txt`

default: dist

all:

dist:
	git archive HEAD -o $(package_name)-$(version).zip
	composer install --no-dev
	rm -rf vendor/bin
	zip -r $(package_name)-$(version).zip vendor
