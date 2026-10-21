import { Component, ElementRef, Input, OnChanges, ViewChild } from '@angular/core';
import QRCode from 'qrcode';

/**
 * QR code d'un lot (Q13) : il contient le code du lot, que le gestionnaire
 * scanne avec l'application à la réception. Affichable et imprimable.
 */
@Component({
  selector: 'app-qr-lot',
  standalone: true,
  template: `
    <figure class="qr">
      <canvas #canvas [attr.aria-label]="'QR code du lot ' + code" role="img"></canvas>
      <figcaption><code class="code-lot">{{ code }}</code></figcaption>
      <button type="button" class="btn-secondary btn-sm" (click)="imprimer()">Imprimer l'étiquette</button>
    </figure>
  `,
  styles: [`
    .qr { margin:0; display:inline-grid; justify-items:center; gap:.35rem; padding:.75rem; border:1px solid #e5e7eb; border-radius:10px; background:#fff; }
    figcaption { font-size:.9rem; }
  `],
})
export class QrLotComponent implements OnChanges {
  @Input({ required: true }) code = '';
  @ViewChild('canvas', { static: true }) private canvas!: ElementRef<HTMLCanvasElement>;

  ngOnChanges(): void {
    if (this.code) QRCode.toCanvas(this.canvas.nativeElement, this.code, { width: 160, margin: 1, errorCorrectionLevel: 'M' }).catch(() => {});
  }

  imprimer(): void {
    const image = this.canvas.nativeElement.toDataURL('image/png');
    const fenetre = window.open('', '_blank', 'width=420,height=520');
    if (!fenetre) return;
    fenetre.document.write(`<!doctype html><title>${this.code}</title><body style="font-family:sans-serif;text-align:center;padding:24px">
      <img src="${image}" style="width:240px;height:240px" alt="QR code du lot"><p style="font-size:20px;font-weight:bold">${this.code}</p>
      <p>SenAgriLink — à scanner à la réception</p><script>onload=()=>{print()}</script></body>`);
    fenetre.document.close();
  }
}
