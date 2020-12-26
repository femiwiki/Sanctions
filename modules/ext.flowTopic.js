(function () {
  'use strict';

  // TODO check user right

  mw.flow.ve.Target.static.toolbarGroups.splice(
    0,
    0,
    {
      name: 'agreewithdays',
      include: ['sanctions-agreewithdays'],
    },
    {
      name: 'agree',
      include: ['sanctions-agree'],
    },
    {
      name: 'disagree',
      include: ['sanctions-disagree'],
    }
  );
})();
