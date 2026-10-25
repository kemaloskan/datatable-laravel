{{--
  The same placing as @acunsoft/datatable-dom's placeMenu(), for a menu's
  @toggle: an open panel that would stick out of the window is shifted
  sideways (--adt-menu-shift). Printed inside an attribute, so the script
  uses single quotes only.
--}}
(() => { const menu = $el; menu.style.removeProperty('--adt-menu-shift'); const panel = menu.querySelector(':scope > :not(summary)'); if (! menu.open || ! panel) return; const view = document.documentElement.clientWidth; const box = panel.getBoundingClientRect(); let shift = box.right > view - 8 ? view - 8 - box.right : 0; if (box.left + shift < 8) shift = 8 - box.left; if (shift !== 0) menu.style.setProperty('--adt-menu-shift', Math.round(shift) + 'px'); })()
