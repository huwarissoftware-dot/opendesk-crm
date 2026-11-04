// OpenDesk CRM - small UI helpers (file size hints, service worker registration).
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('input[type=file]').forEach(function(inp){
    inp.addEventListener('change',function(){
      for (var i=0;i<inp.files.length;i++){
        var f=inp.files[i]; var ext=(f.name.split('.').pop()||'').toLowerCase();
        var imgZip=['jpg','jpeg','png','gif','webp','zip'].indexOf(ext)>=0;
        var limit=(imgZip?10:15)*1024*1024;
        if (f.size>limit){ alert(f.name+' exceeds the '+(imgZip?'10 MB':'15 MB')+' limit.'); inp.value=''; return; }
      }
    });
  });
  if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(function(){}); }
});
