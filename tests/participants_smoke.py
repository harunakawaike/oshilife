"""Step 3-1：同行者の本人・イベント境界、利用終了、自分の一意性と既存データ保持をHTTPで検証する。"""
import event_test_support as support
from event_test_support import *
import os, tempfile
before=db('snapshot')
a,b,anonymous=Client(),Client(),Client()
try:
    aid=a.register_login(emails[0]);bid=b.register_login(emails[1])
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'actor','emoji':'🎟️'},201)['oshi']['id']
    post(b,'oshis/follow',{'oshi_id':oid})
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都','address':'検証'},201)['id']
    def create(title):
        return post(a,'events/create',{'oshi_id':oid,'title':title,'event_type':'performance','allow_duplicate':True,'venue_id':vid,'event_date':'2026-12-10','status':'scheduled'},201)['event']['id']
    first,second=create('同行者検証'),create('別イベント')
    def manage(client,event):
        post(client,'events/status/update',{'event_id':event,'entry_method':'no_application','participation_status':'confirmed','trip_type':'local','ticket_amount':'0'})
    # 公開イベントの閲覧権限だけで個人管理は作れない。
    b.call(f'api/events/companions/list.php?event_id={first}',status=404)
    post(b,'events/companions/create',{'event_id':first,'name':'未登録'},404)
    manage(a,first);manage(a,second)
    baseline=db('snapshot')
    anonymous.call(f'api/events/companions/list.php?event_id={first}',status=401)
    a.call('api/events/companions/create.php','POST',{'event_id':first,'name':'CSRF'},status=419,token=False)
    post(b,'events/companions/create',{'event_id':first,'name':'別ユーザー'},404)
    b.call(f'event_companions.php?event_id={first}',status=404)
    assert get(a,'events/companions/list',event_id=first)['participants']==[]
    # GETで自分の行を勝手に追加しない。
    a.call(f'event_companions.php?event_id={first}')
    assert get(a,'events/companions/list',event_id=first)['participants']==[]
    p1=post(a,'events/companions/create',{'event_id':first,'name':'Aさん','note':'最初のメモ'},201)['id']
    p2=post(a,'events/companions/create',{'event_id':first,'name':'Aさん','note':''},201)['id']
    assert p1!=p2
    def rows():return get(a,'events/companions/list',event_id=first)['participants']
    data=rows();assert len(data)==3
    self_id=next(p['id'] for p in data if p['self_marker'] is not None)
    for _ in range(3):assert post(a,'events/companions/self',{'event_id':first,'name':'自分','note':'本人メモ'})['id']==self_id
    assert len([p for p in rows() if p['self_marker'] is not None])==1
    post(a,'events/companions/update',{'event_id':first,'id':p1,'name':'Bさん','note':'<script>alert(1)</script>\nメモ'})
    updated=next(p for p in rows() if p['id']==p1);assert updated['name']=='Bさん' and updated['note'].startswith('<script>')
    _,html=a.call(f'event_companions.php?event_id={first}')
    assert '&lt;script&gt;alert(1)&lt;/script&gt;' in html and '<script>alert(1)</script>' not in html
    for action in ['update','archive','restore']:
        values={'event_id':second,'id':p1,'name':'不正','note':''}
        post(a,'events/companions/'+action,values,404)
    for raw in [{'name':''},{'name':['bad']},{'name':'x'*151},{'name':'OK','note':'x'*3001},{'name':'OK','is_self':1},{'name':'OK','self_marker':1},{'name':'OK','user_id':bid}]:
        post(a,'events/companions/create',{'event_id':first,**raw},422)
    post(a,'events/companions/archive',{'event_id':first,'id':self_id},422)
    post(a,'events/companions/archive',{'event_id':first,'id':p1})
    post(a,'events/companions/archive',{'event_id':first,'id':p1})
    assert next(p for p in rows() if p['id']==p1)['archived_at'] is not None
    post(a,'events/companions/update',{'event_id':first,'id':p1,'name':'終了中の編集'},409)
    post(a,'events/companions/restore',{'event_id':first,'id':p1})
    assert next(p for p in rows() if p['id']==p1)['archived_at'] is None
    post(a,'events/companions/archive',{'event_id':first,'id':p1})
    assert baseline==db('snapshot'),'同行者処理が既存21テーブルを変更した'
    # 同じ公開イベントを本人管理しても、それぞれの名簿は完全に分離。
    manage(b,first)
    assert get(b,'events/companions/list',event_id=first)['participants']==[]
    for action in ['update','archive','restore']:
        post(b,'events/companions/'+action,{'event_id':first,'id':p2,'name':'他人'},404)
    post(b,'events/companions/create',{'event_id':first,'name':'他ユーザー専用名'},201)
    assert not any(p['name']=='他ユーザー専用名' for p in rows())
    _,public_html=a.call(f'event_detail.php?id={first}')
    assert '他ユーザー専用名' not in public_html and 'Bさん' not in public_html
    if os.environ.get('OSHILIFE_BROWSER_TEST')=='1':
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as config:
            json.dump({'base':args.base,'cookies':[{'name':c.name,'value':c.value,'path':c.path} for c in a.jar],'pages':[f'event_detail.php?id={first}',f'event_companions.php?event_id={first}']},config);config.flush()
            subprocess.run(['node',str(root/'tests/participants_browser.mjs'),config.name],check=True,timeout=120)
finally:
    after=db('cleanup');assert before==after,'既存データが変化しました'
print(f'Step 3-1: {support.checks} HTTP checks passed; existing 21 tables unchanged.')
