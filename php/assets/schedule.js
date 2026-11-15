(function () {
  'use strict';

  var rail = document.querySelector('[data-schedule-dates]');
  var selected = rail && rail.querySelector('[aria-current="date"]');
  if (rail && selected) {
    var railBox = rail.getBoundingClientRect();
    var selectedBox = selected.getBoundingClientRect();
    rail.scrollLeft += selectedBox.left - railBox.left - (rail.clientWidth - selected.clientWidth) / 2;
  }

  var comparison = document.querySelector('.schedule-comparison');
  if (comparison && document.querySelector('.schedule-timeline') && window.matchMedia('(max-width: 860px)').matches) {
    comparison.open = false;
  }
}());
