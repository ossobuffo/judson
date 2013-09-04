#!/bin/bash

today=`date "+%Y%m%d"`

for x in *.sfd; do
  y=`echo $x | sed -s 's:.sfd:.ttf:g'`
  scripts/normalize-glyph-names.php ${x} > build/${x}
  cd build
  fontforge -lang=py -script ../scripts/generate-ttf.ff ${x} 
  ttfautohint ${y} hinted-${y}
  mv hinted-${y} ${y}
  cd ..
done
cd build
cp ../*.txt .
tar czf judson-${today}.tar.gz *.ttf *.txt
tar czf judson-sources-${today}.tar.gz *.sfd *.txt
if [ -a judson.zip ]; then
  rm judson.zip
fi
# Specify TTF files in sequence so that Regular appears first in the file.
zip -u judson.zip Judson.ttf JudsonItalic.ttf JudsonBold.ttf JudsonBoldItalic.ttf *.txt *.sfd
rm *.ttf *.txt *.sfd
