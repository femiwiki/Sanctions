'use strict';
const Page = require('wdio-mediawiki/Page');

class SanctionsPage extends Page {
  get reasonsDisabledParticipation() {
    return $('.sanctions-reasons-disabled-participation');
  }
  get target() {
    return $('#sanctions-target');
  }
  get forInsultingName() {
    return $('#forInsultingName');
  }
  get submitButton() {
    return $('#sanctions-submit-button');
  }
  get sanctions() {
    return $('.sanctions');
  }

  open(subpage) {
    super.openTitle('Special:Sanctions/' + subpage, { uselang: 'qqx' });
  }
  submit(target, isForInsultingName = false) {
    this.target.setValue(target);
    if (isForInsultingName) {
      this.forInsultingName.click();
    }
    this.submitButton.click();
  }
}
module.exports = new SanctionsPage();
