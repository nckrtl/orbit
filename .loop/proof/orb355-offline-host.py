#!/usr/bin/env python3
"""Prepare and verify only the disposable extra Node during unreachable tests."""
import argparse,hashlib,ipaddress,json,os,pathlib,re,socket,subprocess
p=argparse.ArgumentParser();p.add_argument('action',choices=['prepare','stop','restore','allow-recovery','delete-recovery']);p.add_argument('tag');a=p.parse_args()
assert re.fullmatch(r'[a-z0-9-]{1,40}',a.tag)
assert os.geteuid()==0
assert socket.gethostname().startswith('orbit-e2e-orb-355-') and socket.gethostname().endswith('-app-prod-2'),socket.gethostname()
os.umask(0o077)
root=pathlib.Path('/home/orbit/.local/state/orbit-cli-ux/ORB-355/offline-host')
root.mkdir(parents=True,exist_ok=True)
state=root/(a.tag+'.json');sentinel=pathlib.Path('/var/tmp/orb355-offline-'+a.tag)
paths=['/etc/hostname','/etc/os-release','/etc/ssh/sshd_config','/etc/wireguard/orbit.conf','/etc/ufw/user.rules','/etc/ufw/user6.rules',str(sentinel)]
def snapshot():
 return {'hostname':socket.gethostname(),'boot_id':pathlib.Path('/proc/sys/kernel/random/boot_id').read_text().strip(),'hashes':{p:hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest() for p in paths}}
if a.action=='prepare':
 assert not state.exists() and not sentinel.exists()
 sentinel.write_text(a.tag+'\n');state.write_text(json.dumps(snapshot(),indent=2))
 subprocess.run(['systemctl','is-active','--quiet','ssh.service'],check=True)
 print(json.dumps({'prepared':True,'tag':a.tag,'state':str(state)}))
elif a.action=='stop':
 assert json.loads(state.read_text())==snapshot()
 subprocess.run(['systemctl','stop','ssh.socket','ssh.service'],check=True)
 assert subprocess.run(['systemctl','is-active','--quiet','ssh.service']).returncode!=0
 assert subprocess.run(['systemctl','is-active','--quiet','ssh.socket']).returncode!=0
 print(json.dumps({'ssh_stopped':True,'tag':a.tag,'management':'Incus exec retained'}))
elif a.action=='restore':
 before=json.loads(state.read_text());after=snapshot()
 (root/(a.tag+'-comparison.json')).write_text(json.dumps({'before':before,'after':after,'preserved':before==after},indent=2))
 subprocess.run(['systemctl','start','ssh.service','ssh.socket'],check=True)
 subprocess.run(['systemctl','is-active','--quiet','ssh.service'],check=True)
 assert before==after,(before,after)
 sentinel.unlink();result={'restored':True,'preserved':True,'tag':a.tag,'before':before,'after':after,'sentinel_removed':True}
 (root/(a.tag+'-verified.json')).write_text(json.dumps(result,indent=2));print(json.dumps(result))
else:
 descriptor=json.loads((root/'recovery.json').read_text())
 source=str(ipaddress.IPv4Address(descriptor['gateway_public_ssh_host']))
 assert ipaddress.ip_address(source).is_private
 addresses=json.loads(subprocess.check_output(['ip','-j','address'],text=True))
 local={v['local'] for link in addresses for v in link.get('addr_info',[])}
 assert descriptor['extra_public_ssh_host'] in local
 tag='orb355-offline-recovery-'+a.tag
 rule=['allow','proto','tcp','from',source,'to','any','port','22','comment',tag]
 before=subprocess.check_output(['ufw','status','numbered'],text=True)
 if a.action=='allow-recovery':
  assert tag not in before
  argv=['ufw']+rule
 else:
  assert tag in before
  argv=['ufw','--force','delete']+rule
 result=subprocess.run(argv,capture_output=True,text=True)
 assert result.returncode==0,(result.stdout,result.stderr)
 after=subprocess.check_output(['ufw','status','numbered'],text=True)
 assert (tag in after)==(a.action=='allow-recovery')
 evidence={'action':a.action,'argv':argv,'before':before,'after':after,'exit':result.returncode,'stdout':result.stdout,'stderr':result.stderr}
 (root/(a.tag+'-'+a.action+'.json')).write_text(json.dumps(evidence,indent=2));print(json.dumps(evidence))
