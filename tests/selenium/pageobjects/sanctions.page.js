'use strict';
const Page = require('wdio-mediawiki/Page');

class SanctionsPage extends Page {
  get reasonsDisabledParticipation() {
    return $('.sanctions-reasons-disabled-participation');
  }
  open(subpage) {
    super.openTitle('Special:Sanctions/' + subpage, { uselang: 'en' });
  }
}
module.exports = new SanctionsPage();
