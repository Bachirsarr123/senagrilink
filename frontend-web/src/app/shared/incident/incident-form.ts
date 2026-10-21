import { Component, EventEmitter, Input, Output, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Choix } from '../livraison/livraison';

/** Fenêtre de déclaration : type d'incident (ou d'anomalie) et description obligatoires. */
@Component({
  selector: 'app-incident-form',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="modal-overlay" (click)="fermer.emit()">
      <div class="modal-box" role="dialog" aria-modal="true" [attr.aria-label]="titre" (click)="$event.stopPropagation()">
        <h2>{{ titre }}</h2>
        @if (sousTitre) { <p class="note">{{ sousTitre }}</p> }
        @if (erreur) { <div class="alert alert-error" role="alert">{{ erreur }}</div> }
        <div class="form-group">
          <label for="type-incident">Que se passe-t-il ? *</label>
          <select id="type-incident" [(ngModel)]="type">
            <option value="">— Choisir —</option>
            @for (t of types; track t.code) { <option [value]="t.code">{{ t.libelle }}</option> }
          </select>
        </div>
        <div class="form-group">
          <label for="description-incident">Précisez *</label>
          <textarea id="description-incident" rows="3" maxlength="1000" [(ngModel)]="description"></textarea>
        </div>
        @if (manque()) { <p class="field-error">Choisissez le type et décrivez la situation.</p> }
        <div class="btn-group">
          <button class="btn-danger" [disabled]="envoi" (click)="valider()">{{ envoi ? 'Envoi…' : 'Envoyer' }}</button>
          <button class="btn-ghost" (click)="fermer.emit()">Annuler</button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .modal-box { --modal-largeur: 480px; }
    select, textarea { padding:.55rem .8rem; border:1px solid #e5e7eb; border-radius:6px; font-size:.9rem; width:100%; font-family:inherit; }
    .note { font-size:.85rem; color:#6b7280; margin:0 0 1rem; }
    .field-error { color:#dc2626; font-size:.85rem; }
  `],
  styleUrls: ['../../features/shared.styles.scss'],
})
export class IncidentFormComponent {
  @Input({ required: true }) titre = '';
  @Input() sousTitre = '';
  @Input({ required: true }) types: Choix[] = [];
  @Input() erreur: string | null = null;
  @Input() envoi = false;
  @Output() envoyer = new EventEmitter<{ type: string; description: string }>();
  @Output() fermer = new EventEmitter<void>();

  type = '';
  description = '';
  manque = signal(false);

  valider(): void {
    if (!this.type || !this.description.trim()) { this.manque.set(true); return; }
    this.manque.set(false);
    this.envoyer.emit({ type: this.type, description: this.description.trim() });
  }
}
