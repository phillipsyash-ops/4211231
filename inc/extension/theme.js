// Ставим тему до отрисовки, чтобы не было вспышки
try { if (localStorage.getItem('theme') === 'dark') document.documentElement.dataset.theme = 'dark'; } catch (e) {}
