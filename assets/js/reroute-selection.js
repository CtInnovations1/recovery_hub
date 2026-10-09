window.openRecoveryRerouteSelection=async function(ids){
    if(!ids.length)return alert('Select serialized devices first.');
    try{const response=await fetch('/api/reroute/select.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({device_ids:ids})});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Unable to store selection.');location.href='/pages/reroute-batch.php';}catch(e){alert(e.message);}
};
