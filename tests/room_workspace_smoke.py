"""ルームUI専用の一時メンバー・複数イベントを用意し、PC/スマホの同一ページ操作を確認する。"""
from event_test_support import *
import os,tempfile
assert os.environ.get('DB_NAME','').startswith('oshilife_v2_rooms_verify_expenses_')
a,b,c=Client(),Client(),Client();before=db('snapshot')
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1]);c.register_login(emails[2])
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'group','emoji':'🎵'},201)['oshi']['id']
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都'},201)['id']
    events=[]
    for n in [1,2]:
        events.append(post(a,'events/create',{'oshi_id':oid,'venue_id':vid,'title':'東京ドーム公演 '+str(n),'event_type':'live','event_date':f'2027-02-{16+n}','start_time':'18:00','status':'scheduled'},201)['event']['id'])
    room=post(a,'rooms/create',{'name':'東京ドーム連番ルーム'},201)['id']
    link=post(a,'rooms/links/create',{'room_id':room},201);token=urllib.parse.parse_qs(urllib.parse.urlparse(link['url']).query)['token'][0]
    post(b,'rooms/links/join',{'token':token})
    c.call('room_detail.php?room_id='+str(room),status=404)
    with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as cfg:
        json.dump({'base':args.base,'roomId':room,'userIds':[aid,bid],'eventIds':events,'cookies':[{'name':x.name,'value':x.value,'path':x.path} for x in a.jar],'memberCookies':[{'name':x.name,'value':x.value,'path':x.path} for x in b.jar]},cfg);cfg.flush()
        subprocess.run(['node',str(root/'tests/room_workspace_browser.mjs'),cfg.name],check=True,timeout=180)
finally:
    assert db('cleanup')==before,'既存データの変更がないこと'
print('Room workspace UI fixtures cleaned; existing personal data unchanged.')
