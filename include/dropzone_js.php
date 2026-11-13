<script>
(function(){
  var z=document.getElementById('dz'); if(!z) return;
  var i=z.querySelector('input'), p=z.querySelector('.picked'), max=<?= ECMS_MAX_UPLOAD_BYTES ?>;
  function show(){ if(i.files.length){ var f=i.files[0]; p.textContent=f.name+' ('+(f.size/1024<1024?Math.round(f.size/1024)+' KB':(f.size/1048576).toFixed(2)+' MB')+')'; if(f.size>max){p.textContent+=' - too large (max 5 MB)';p.style.color='#cc3340';} else {p.style.color='';} } }
  i.addEventListener('change',show);
  ['dragenter','dragover'].forEach(function(e){z.addEventListener(e,function(ev){ev.preventDefault();z.classList.add('drag');});});
  ['dragleave','drop'].forEach(function(e){z.addEventListener(e,function(ev){ev.preventDefault();z.classList.remove('drag');});});
  z.addEventListener('drop',function(ev){ if(ev.dataTransfer.files.length){ i.files=ev.dataTransfer.files; show(); } });
})();
</script>
