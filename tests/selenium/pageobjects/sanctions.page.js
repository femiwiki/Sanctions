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
  get executeButton() {
    return $('.sanction-execute-button');
  }
  getSanction({ voted = false, mySanction = false } = {}) {
    let classSelector = '.sanction';
    let notClassSelector = '';

    if (mySanction) classSelector += '.my-sanction';
    if (voted) {
      classSelector += '.voted';
    } else {
      notClassSelector += '.voted';
    }

    return $(classSelector + `:not(${notClassSelector})`);
  }
  getSanctionLink({ voted = false, mySanction = false } = {}) {
    const sanction = this.getSanction({ voted: voted, mySanction: mySanction });
    return sanction.$('a.sanction-type');
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
