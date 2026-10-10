const fs = require('fs');
const p = 'resources/js/Pages/Account/Team.vue';
let s = fs.readFileSync(p, 'utf8');
function rep(a, b) {
    if (!s.includes(a)) throw new Error('missing ' + a.slice(0, 60));
    s = s.replace(a, b);
}
rep('class="flex flex-col gap-3 px-4 py-3 lg:flex-row lg:items-center"', 'class="flex flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3"');
rep('<div class="flex min-w-0 flex-1 items-center gap-3">', '<div class="flex min-w-[15rem] flex-1 basis-60 items-center gap-3">');
rep('class="grid grid-cols-2 gap-x-6 gap-y-1 text-sm lg:w-[28rem] lg:grid-cols-3"', 'class="grid min-w-[16rem] flex-1 basis-80 grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-3"');
rep('class="col-span-2 lg:col-span-1"', 'class="col-span-2 sm:col-span-1"');
rep('<div class="flex items-center gap-2 lg:w-28 lg:justify-end">', '<div class="flex items-center gap-2">');
rep('<div class="flex flex-wrap gap-2 lg:w-52 lg:justify-end">', '<div class="flex flex-wrap gap-2">');
fs.writeFileSync(p, s);
console.log('ok');
