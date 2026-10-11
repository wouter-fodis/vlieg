from PIL import Image, ImageDraw
import math, sys
# Tekent de app-iconen; de base64 van de PNG's staat in proxy.php (?app=icon).
# Gebruik: python3 tools/make-icons.py <map>
S=sys.argv[1] if len(sys.argv) > 1 else '.'; N=1024
def make(maskable=False):
    im=Image.new('RGB',(N,N),'#141414'); d=ImageDraw.Draw(im)
    if not maskable:
        pass
    c=N/2; sc=0.78 if maskable else 1.0
    def P(x,y): return (c+(x-c)*sc, c+(y-c)*sc)
    # split-flap naad
    d.rectangle([0,N/2-5,N,N/2+5],fill='#000')
    # amber geluidsgolven links en rechts
    for i,r in enumerate([310,380,450]):
        w=int(26*sc)
        for a0,a1 in [(154,206),(-26,26)]:
            bb=[*P(c-r,c-r),*P(c+r,c+r)]
            d.arc(bb,a0,a1,fill='#f0b33c',width=w)
    # vliegtuig van boven, neus omhoog
    pts=[(512,170),(548,230),(552,430),(700,575),(700,622),(552,560),(545,730),(625,790),(625,830),(512,800),(399,830),(399,790),(479,730),(472,560),(324,622),(324,575),(472,430),(476,230)]
    d.polygon([P(x,y) for x,y in pts],fill='#f2f2ef')
    return im
for name,m in [('icon',False),('maskable',True)]:
    big=make(m)
    for s in ([512,192,180] if name=='icon' else [512]):
        big.resize((s,s),Image.LANCZOS).save(f'{S}/{name}-{s}.png',optimize=True)
