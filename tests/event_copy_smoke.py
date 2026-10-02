"""イベントコピーとFC先行のHTTP・画面初期値・既存データ保持を検証する。"""
import event_test_support as support
from event_test_support import *
from html.parser import HTMLParser
import os,tempfile

class EventForm(HTMLParser):
    """コピー画面の最初のイベントフォームを、ブラウザ同様の送信値に取り出す。"""
    def __init__(self):
        super().__init__();self.active=False;self.values={};self.api=None;self.select=None;self.textarea=None
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if tag=='form' and self.api is None:
            self.active=True;self.api=a.get('data-api')
        if not self.active:return
        if tag=='input' and 'name' in a:self.values[a['name']]=a.get('value','')
        if tag=='select':self.select=a.get('name')
        if tag=='option' and self.select and (self.select not in self.values or 'selected' in a):self.values[self.select]=a['value']
        if tag=='textarea':self.textarea=a.get('name');self.values[self.textarea]=''
    def handle_endtag(self,tag):
        if tag=='form':self.active=False
        if tag=='select':self.select=None
        if tag=='textarea':self.textarea=None
    def handle_data(self,data):
        if self.active and self.textarea:self.values[self.textarea]+=data

before=db('snapshot');a,b=Client(),Client()
try:
    a.register_login(emails[0]);b.register_login(emails[1])
    oid=post(a,'oshis/create',{'name':'Payment-'+key,'oshi_type':'actor','emoji':'🎟️'},201)['oshi']['id']
    vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都','address':'元会場'},201)['id']
    new_vid=post(a,'venues/create',{'name':'Payment-'+key,'prefecture':'東京都','address':'別会場'},201)['id']
    original=post(a,'events/create',{'oshi_id':oid,'title':'コピー元 <テスト>','event_type':'sports','venue_id':vid,'event_date':'2026-12-10','open_time':'12:00','start_time':'13:00','end_time':'15:00','note':'共有メモ','status':'completed'},201)['event']
    eid=original['id']
    post(a,'events/status/update',{'event_id':eid,'entry_method':'fanclub_presale','application_status':'applied','lottery_status':'won','participation_status':'confirmed','trip_type':'local','ticket_amount':'10000','ticket_payment_status':'paid','note':'個人メモ'})
    post(a,'events/companions/create',{'event_id':eid,'name':'コピーしない同行者'},201)
    state=get(a,'events/status',event_id=eid)['status']
    assert state['entry_method']=='lottery' and state['sales_type']=='fanclub' and state['lottery_status']=='won'
    assert get(a,'events/detail',id=eid)['event']['entry_label']=='ファンクラブ先行受付'
    source=get(a,'events/detail',id=eid)['event'];todos=get(a,'events/todos/list',event_id=eid)['todos']
    assert todos
    _,detail=a.call(f'event_detail.php?id={eid}')
    assert f'copy_from={eid}' in detail and 'value="fanclub_presale" selected' in detail
    b.call(f'event_form.php?copy_from={eid}',status=404)
    a.call(f'event_form.php?copy_from={eid}&id={eid}',status=422)
    _,html=a.call(f'event_form.php?copy_from={eid}')
    f=EventForm();f.feed(html)
    assert f.api=='events/create' and 'id' not in f.values
    for k,v in {'title':'コピー元 <テスト>','event_type':'sports','note':'共有メモ','open_time':'12:00','start_time':'13:00','end_time':'15:00','status':'scheduled'}.items():assert f.values[k]==v,(k,f.values)
    assert '個人メモ' not in html and 'コピーしない同行者' not in html
    f.values.update(event_date='2026-12-11',open_time='15:00',start_time='16:00',end_time='18:00',venue_id=new_vid)
    copy=post(a,'events/create',f.values,201)['event'];cid=copy['id']
    assert cid!=eid and copy['schedule_id']!=source['schedule_id']
    assert copy['event_date']=='2026-12-11' and str(copy['venue_id'])==str(new_vid) and copy['start_time']=='16:00'
    assert copy['event_type']=='sports' and copy['event_type_label']=='舞台'
    assert copy['participation_status']=='considering' and copy['ticket_amount'] is None and copy['ticket_expense_id'] is None and copy['trip_id'] is None
    assert get(a,'events/todos/list',event_id=cid)['todos']==[]
    assert get(a,'events/companions/list',event_id=cid)['participants']==[]
    assert get(a,'events/detail',id=eid)['event']==source
    assert get(a,'events/todos/list',event_id=eid)['todos']==todos
    # 同一日程を誤って二重登録しない従来の確認も維持。
    post(a,'events/create',f.values,409)
    if os.environ.get('OSHILIFE_BROWSER_TEST')=='1':
        with tempfile.NamedTemporaryFile(mode='w',suffix='.json') as cfg:
            page=f'event_detail.php?id={eid}'
            json.dump({'base':args.base,'cookies':[{'name':c.name,'value':c.value,'path':c.path} for c in a.jar],'pages':[page,f'event_form.php?copy_from={eid}',f'event_detail.php?id={cid}'],'managementExpect':{page:{'entry':'fanclub_presale','free':False}}},cfg);cfg.flush()
            subprocess.run(['node',str(root/'tests/events_browser.mjs'),cfg.name],check=True,timeout=120)
    # 通常抽選へ変更でき、メモだけの更新で受付方式が失われない。
    post(a,'events/status/update',{'event_id':eid,'note':'変更後'})
    assert get(a,'events/status',event_id=eid)['status']['sales_type']=='fanclub'
    post(a,'events/status/update',{'event_id':eid,'entry_method':'lottery','sales_type':'none'})
    assert get(a,'events/status',event_id=eid)['status']['sales_type']=='none'
finally:
    assert before==db('cleanup'),'既存データが変わりました'
print(f'Event copy / FC: {support.checks} HTTP checks passed; all existing 21 table rows unchanged.')
